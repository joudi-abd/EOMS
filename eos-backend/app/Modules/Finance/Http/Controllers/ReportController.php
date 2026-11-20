<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Reports\CostReports;
use App\Modules\Finance\Services\Reports\LedgerReports;
use App\Modules\Finance\Services\Reports\PartyReports;
use App\Modules\Finance\Services\Reports\ProjectReports;
use App\Modules\Finance\Services\Reports\ReportCsv;
use App\Modules\Finance\Services\Reports\ReportFrame;
use App\Modules\Finance\Services\Reports\ReportFrames;
use App\Modules\Finance\Services\Reports\StatementReports;
use App\Modules\Finance\Services\Reports\TreasuryReports;
use App\Modules\Identity\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finance reports T1–T23 (spec §10). Every report takes the common frame (ReportFrames::RULES): `branch_id`, `from`,
 * `to` / `as_of`, `currency`, `view=separate|consolidated`, `rate_mode`, and `format=csv` for the Excel export.
 */
class ReportController extends Controller
{
    public function __construct(private FinanceScope $scope, private ReportFrames $frames, private ReportCsv $csv) {}

    /** T1 box balances on a day. */
    public function boxBalances(Request $request, TreasuryReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, [], period: false);

        return $this->respond($input, 'box-balances', $reports->boxBalances($frame));
    }

    /** T2 box movement: per currency, opening, movements with running balance, closing. Defaults to this month. */
    public function boxStatement(Request $request, TreasuryReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['box_id' => ['required', 'integer', 'exists:fin_cash_boxes,id']]);
        $box = CashBox::query()->findOrFail($input['box_id']);
        $this->scope->assertBranch($request->user(), 'finance.reports', $box->branch_id);

        return $this->respond($input, 'box-statement', $reports->boxMovement($frame, $box));
    }

    /** T3 journal (paginated; CSV = every matching line). */
    public function journal(Request $request, LedgerReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['account_id' => ['sometimes', 'integer'], 'party_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'], 'voucher_type' => ['sometimes', 'string', 'max:30'], 'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        if (($input['format'] ?? 'json') === 'csv') {
            return $this->csv->download('journal', ['rows' => $reports->journalRows($frame, $input)]);
        }
        ['report' => $report, 'page' => $page] = $reports->journal($frame, $input, (int) ($input['per_page'] ?? 25));

        return response()->json(['data' => $report, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    /** T4 general ledger of one or several accounts. */
    public function generalLedger(Request $request, LedgerReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['account_id' => ['required_without:account_ids', 'integer'],
            'account_ids' => ['required_without:account_id', 'array', 'max:50'], 'account_ids.*' => ['integer']]);
        $ids = $input['account_ids'] ?? [$input['account_id']];

        return $this->respond($input, 'general-ledger', $reports->generalLedger($frame, array_map('intval', $ids)));
    }

    /** T5 trial balance. */
    public function trialBalance(Request $request, LedgerReports $reports): Response
    {
        [$frame, $input] = $this->frame($request);

        return $this->respond($input, 'trial-balance', $reports->trialBalance($frame));
    }

    /** T6 income statement (company; per branch with `by_branch=1`). */
    public function incomeStatement(Request $request, StatementReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['by_branch' => ['sometimes', 'boolean']]);

        return $this->respond($input, 'income-statement', $reports->incomeStatement($frame, $request->boolean('by_branch')));
    }

    /** T7 balance sheet on a day. */
    public function balanceSheet(Request $request, StatementReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, [], period: false);

        return $this->respond($input, 'balance-sheet', $reports->balanceSheet($frame));
    }

    /** T8 projects summary (finance.projects.view reach). */
    public function projectsSummary(Request $request, ProjectReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, $this->projectFilters(), period: false, permission: null);

        return $this->respond($input, 'projects-summary', $reports->summary($frame, $request->user(), $input));
    }

    /** T9 project statement (internal or client copy). */
    public function projectStatement(Request $request, ProjectReports $reports): Response
    {
        $input = $request->validate(['project_id' => ['required', 'integer'], 'variant' => ['sometimes', 'in:internal,client'],
            'hide_internal' => ['sometimes', 'boolean'], 'date' => ['sometimes', 'date_format:Y-m-d'], 'format' => ['sometimes', 'in:json,csv']]);

        return $this->respond($input, 'project-statement', $reports->statement($request->user(), (int) $input['project_id'], $input['variant'] ?? 'internal',
            $request->boolean('hide_internal'), $input['date'] ?? null));
    }

    /** T10 budget against actual, over-budget lines first. */
    public function budgetVsActual(Request $request, ProjectReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, [...$this->projectFilters(), 'project_id' => ['sometimes', 'integer'],
            'project_cost_category_id' => ['sometimes', 'integer']], period: false, permission: null);

        return $this->respond($input, 'budget-vs-actual', $reports->budgetVsActual($frame, $request->user(), $input));
    }

    /** T11 profitability ranking. */
    public function profitability(Request $request, ProjectReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, $this->projectFilters(), period: false, permission: null);

        return $this->respond($input, 'project-profitability', $reports->profitabilityRanking($frame, $request->user(), $input));
    }

    /** T12 party statement within the report reach. Without `from` the statement starts at the first line. */
    public function partyStatement(Request $request, PartyReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['party_id' => ['required', 'integer', 'exists:fin_parties,id'], 'project_id' => ['sometimes', 'integer']]);

        return $this->respond($input, 'party-statement', $reports->statement($frame, Party::query()->findOrFail($input['party_id']),
            isset($input['project_id']) ? (int) $input['project_id'] : null, ! $request->filled('from')));
    }

    /** T13 contractor balances across projects. */
    public function contractorBalances(Request $request, ProjectReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['party_id' => ['sometimes', 'integer']], period: false);

        return $this->respond($input, 'contractor-balances', $reports->contractorBalances($frame, isset($input['party_id']) ? (int) $input['party_id'] : null));
    }

    /** T14 receivables / payables aging. */
    public function aging(Request $request, PartyReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['side' => ['sometimes', 'in:receivable,payable'], 'party_id' => ['sometimes', 'integer']], period: false);

        return $this->respond($input, 'aging', $reports->aging($frame, $input['side'] ?? null, isset($input['party_id']) ? (int) $input['party_id'] : null));
    }

    /** T15 employee advances (EmployeeAdvanceService::report, finance.view branch reach as its own endpoint). */
    public function employeeAdvances(Request $request, EmployeeAdvanceService $advances): Response
    {
        $input = $request->validate(['months' => ['sometimes', 'integer', 'min:1', 'max:24'], 'format' => ['sometimes', 'in:json,csv']]);

        return $this->respond($input, 'employee-advances', $advances->report($request->user(), (int) ($input['months'] ?? 3)));
    }

    /** T16 open custodies with their age (CustodyService::openReport). */
    public function openCustodies(Request $request, CustodyService $custodies): Response
    {
        $input = $request->validate(['format' => ['sometimes', 'in:json,csv']]);

        return $this->respond($input, 'open-custodies', $custodies->openReport($request->user()));
    }

    /** T17 partners' current accounts per month. */
    public function partners(Request $request, PartyReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['party_id' => ['sometimes', 'integer']]);

        return $this->respond($input, 'partners', $reports->partners($frame, isset($input['party_id']) ? (int) $input['party_id'] : null));
    }

    /** T18 expenses by category tree and month. */
    public function expensesByCategory(Request $request, CostReports $reports): Response
    {
        [$frame, $input] = $this->frame($request);

        return $this->respond($input, 'expenses-by-category', $reports->expensesByCategory($frame));
    }

    /** T19 payroll cost by employee, project and component. */
    public function payrollCost(Request $request, CostReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['employee_id' => ['sometimes', 'integer'], 'project_id' => ['sometimes', 'integer']]);

        return $this->respond($input, 'payroll-cost', $reports->payrollCost($frame, isset($input['employee_id']) ? (int) $input['employee_id'] : null,
            isset($input['project_id']) ? (int) $input['project_id'] : null));
    }

    /** T21 exchanges against the bulletin and the fx_position result. */
    public function exchanges(Request $request, TreasuryReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['box_id' => ['sometimes', 'integer']]);

        return $this->respond($input, 'exchanges', $reports->exchanges($frame, isset($input['box_id']) ? (int) $input['box_id'] : null));
    }

    /** T22 cash counts history. */
    public function cashCounts(Request $request, TreasuryReports $reports): Response
    {
        [$frame, $input] = $this->frame($request, ['box_id' => ['sometimes', 'integer'], 'status' => ['sometimes', 'in:draft,submitted,approved,rejected']]);

        return $this->respond($input, 'cash-counts', $reports->cashCounts($frame, isset($input['box_id']) ? (int) $input['box_id'] : null, $input['status'] ?? null));
    }

    /** T23 audit trail of finance, rates and settings (as GET /audit-log), with CSV. */
    public function audit(Request $request, AuditLogger $audit): Response
    {
        $input = $request->validate(['action' => ['sometimes', 'string', 'max:100'], 'user_id' => ['sometimes', 'integer'], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'], 'auditable_type' => ['sometimes', 'string', 'max:100'], 'auditable_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'format' => ['sometimes', 'in:json,csv']]);
        $prefixes = ['finance.', 'fx.', 'settings.'];
        if (($input['format'] ?? 'json') !== 'csv') {
            return ApiResponse::paginated($audit->search($prefixes, $input, (int) ($input['per_page'] ?? 50)));
        }
        // The export walks the pages (100 rows each, at most 10,000 rows).
        $items = [];
        for ($p = 1, $last = 1; $p <= min($last, 100); $p++) {
            Paginator::currentPageResolver(fn () => $p);
            $page = $audit->search($prefixes, $input, 100);
            $last = $page->lastPage();
            array_push($items, ...array_map(fn ($a) => $a->toArray(), $page->items()));
        }

        return $this->csv->download('audit-log', ['items' => $items]);
    }

    /**
     * Validates the common frame plus `$extra` rules and builds the frame.
     *
     * @return array{0: ReportFrame, 1: array<string, mixed>}
     */
    private function frame(Request $request, array $extra = [], bool $period = true, ?string $permission = 'finance.reports'): array
    {
        $input = $request->validate([...ReportFrames::RULES, ...$extra]);

        return [$this->frames->make($input, $request->user(), $permission, $period), $input];
    }

    private function projectFilters(): array
    {
        return ['status' => ['sometimes', 'string', 'max:20'], 'client_party_id' => ['sometimes', 'integer'], 'contract_type' => ['sometimes', 'string', 'max:30']];
    }

    private function respond(array $input, string $report, array $data): Response
    {
        return ($input['format'] ?? 'json') === 'csv' ? $this->csv->download($report, $data) : ApiResponse::ok($data);
    }
}
