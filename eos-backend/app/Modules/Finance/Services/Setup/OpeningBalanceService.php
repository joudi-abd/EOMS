<?php

namespace App\Modules\Finance\Services\Setup;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Models\Partner;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 7 of the setup wizard (spec §12): the opening balances of one branch on the company's single start date, posted
 * as ONE approved `opening_balance` voucher (OpeningBalancePoster adds one opening_balance_equity line per currency):
 *
 * - boxes: Dr (or Cr for a negative balance) the box account per currency;
 * - projects: receipts to date Cr client_advances (client, project) — they count as the project's receipts; costs to
 *   date per cost category Dr project_costs (project, category);
 * - contractor agreements: paid to date Dr project_costs (project, the agreement's category, contractor, agreement);
 *   so these amounts must NOT be repeated in the project's costs;
 * - other parties: Dr other_receivables / Cr other_payables (party);
 * - employee advances still owed: Dr employee_advances (employee) + an advance with its pending installments (no cash);
 * - open custodies: Dr custody (holder, project, custody) + an open custody row (refreshed by the poster);
 * - partners: Dr / Cr partners_current (party).
 *
 * The amount already billed to a project's client is NOT put on the opening voucher: the project ledger counts every
 * client_advances movement outside billing vouchers as a receipt, so a billing line there would reduce the receipts.
 * It is posted instead as an approved `project_billing` voucher of the start date (ProjectBillingPoster, entry #2:
 * Dr client_advances up to the opening receipts, the rest Dr receivables_clients / Cr project_revenue), which leaves
 * the project statement and profitability exactly right: receipts = opening receipts, billed = revenue.
 *
 * One wizard voucher per branch (409 while one is posted), every branch on the same start date.
 */
class OpeningBalanceService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private VoucherService $vouchers, private PostingService $posting, private SystemAccounts $roles,
        private FinanceScope $scope, private CustodyService $custodies, private EmployeeAdvanceService $advances, private ProjectService $projects,
        private SetupWizardService $wizard, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated by SetupOpeningBalancesRequest
     * @return array{voucher: array, billings: list<array>, custody_ids: list<int>, employee_advance_ids: list<int>, check: array}
     */
    public function post(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $date = $data['date'];
            $branch = isset($data['branch_id']) ? Branch::query()->findOrFail($data['branch_id']) : Branch::query()->where('is_head_office', true)->firstOrFail();
            abort_unless($branch->is_active, 422, 'الفرع غير فعّال.');
            $this->scope->assertBranch($actor, 'finance.settings', $branch->id);
            $this->assertFirstForBranch($branch->id, $date);

            $lines = [];
            $extras = []; // line index => [contractor_agreement_id, custody_id]
            $billings = [];
            $this->boxLines($data['boxes'] ?? [], $branch->id, $lines);
            $this->projectLines($data['projects'] ?? [], $branch->id, $lines, $billings);
            $this->agreementLines($data['agreements'] ?? [], $branch->id, $lines, $extras);
            $this->partyLines($data['parties'] ?? [], 'parties', ['other_receivables', 'other_payables'], $lines);
            $this->partyLines($data['partners'] ?? [], 'partners', ['partners_current', 'partners_current'], $lines);
            $advances = $this->advanceLines($data['employee_advances'] ?? [], $lines);
            $custodyIds = $this->custodyLines($data['custodies'] ?? [], $branch->id, $date, $actor, $lines, $extras);
            if ($lines === []) {
                throw ValidationException::withMessages(['boxes' => ['أدخل رصيداً افتتاحياً واحداً على الأقل.']]);
            }

            $voucher = $this->vouchers->createInternal(['type' => 'opening_balance', 'voucher_date' => $date, 'branch_id' => $branch->id,
                'description' => $data['description'] ?? 'الأرصدة الافتتاحية — معالج الإعداد', 'meta' => ['setup_wizard' => true], 'lines' => $lines], $actor);
            foreach ($extras as $index => $dims) {
                VoucherLine::query()->where('voucher_id', $voucher->id)->where('line_no', $index + 1)->update($dims);
            }
            $voucher = $this->posting->approve($voucher, $actor);
            $advanceIds = $this->createAdvances($advances, $voucher, $actor);

            $billingVouchers = [];
            foreach ($billings as $billing) {
                $draft = $this->vouchers->createInternal(['type' => 'project_billing', 'voucher_date' => $date, 'branch_id' => $branch->id,
                    'project_id' => $billing['project_id'], 'party_id' => $billing['party_id'], 'currency_id' => $billing['currency_id'],
                    'amount' => $billing['amount'], 'description' => 'المطالب به حتى تاريخ البدء — رصيد افتتاحي', 'meta' => ['setup_wizard' => true]], $actor);
                $billingVouchers[] = $this->vouchers->present($this->posting->approve($draft, $actor));
            }
            $this->audit->log('finance.opening_balances_posted', $voucher, null, ['date' => $date, 'branch_id' => $branch->id, 'number' => $voucher->number,
                'billings' => array_column($billingVouchers, 'number'), 'custodies' => $custodyIds, 'employee_advances' => $advanceIds], $actor->id);

            return ['voucher' => $this->vouchers->present($voucher), 'billings' => $billingVouchers, 'custody_ids' => $custodyIds,
                'employee_advance_ids' => $advanceIds, 'check' => $this->wizard->openingCheck($date)];
        }, self::TX_ATTEMPTS);
    }

    /** One wizard voucher per branch; every branch opens on the same day. */
    private function assertFirstForBranch(int $branchId, string $date): void
    {
        $existing = Voucher::query()->where('type', 'opening_balance')->where('status', 'approved')->lockForUpdate()->get(['id', 'branch_id', 'voucher_date', 'meta'])
            ->filter(fn (Voucher $v) => ($v->meta['setup_wizard'] ?? false) === true);
        abort_if($existing->contains('branch_id', $branchId), 409, 'رُحّلت الأرصدة الافتتاحية لهذا الفرع مسبقاً؛ اعكس سندها أولاً لإعادة إدخالها.');
        $other = $existing->first();
        if ($other !== null && $other->voucher_date->toDateString() !== $date) {
            throw ValidationException::withMessages(['date' => ['تاريخ البدء الموحّد هو '.$other->voucher_date->toDateString().'.']]);
        }
    }

    private function currencyId(array $row, string $field): int
    {
        $code = strtoupper((string) ($row['currency'] ?? ''));
        $id = Currency::query()->where('code', $code)->where('is_active', true)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages([$field => ['العملة غير موجودة أو غير مفعّلة.']]);
        }

        return (int) $id;
    }

    private function boxLines(array $rows, int $branchId, array &$lines): void
    {
        foreach ($rows as $i => $row) {
            $box = CashBox::query()->find($row['cash_box_id']);
            if ($box === null || $box->branch_id !== $branchId) {
                throw ValidationException::withMessages(["boxes.$i.cash_box_id" => ['الصندوق ليس في هذا الفرع.']]);
            }
            $currencyId = $this->currencyId($row, "boxes.$i.currency");
            if (! CashBoxCurrency::query()->where('cash_box_id', $box->id)->where('currency_id', $currencyId)->exists()) {
                throw ValidationException::withMessages(["boxes.$i.currency" => ["العملة غير مفعّلة في الصندوق {$box->name}."]]);
            }
            $amount = Money::of($row['amount']);
            if (Money::isZero($amount)) {
                continue;
            }
            $lines[] = ['cash_box_id' => $box->id, 'currency_id' => $currencyId, ...$this->side($amount), 'memo' => 'رصيد افتتاحي للصندوق'];
        }
    }

    /** @param  list<array<string, mixed>>  $billings  collected billed-to-date amounts */
    private function projectLines(array $rows, int $branchId, array &$lines, array &$billings): void
    {
        foreach ($rows as $i => $row) {
            $project = $this->projects->find((int) $row['project_id']);
            $profile = ProjectProfile::query()->where('project_id', $row['project_id'])->first();
            if ($project === null || $project->branch_id !== $branchId || $profile === null) {
                throw ValidationException::withMessages(["projects.$i.project_id" => ['المشروع ليس في هذا الفرع أو بلا عقد مالي.']]);
            }
            $currencyId = isset($row['currency']) ? $this->currencyId($row, "projects.$i.currency") : $profile->contract_currency_id;
            $dims = ['project_id' => $project->id];
            $receipts = Money::of($row['receipts'] ?? '0');
            if (Money::isPositive($receipts)) {
                if ($profile->client_party_id === null) {
                    throw ValidationException::withMessages(["projects.$i.receipts" => ['المشروع بلا عميل؛ لا دفعات له.']]);
                }
                $lines[] = ['account_id' => $this->roles->id('client_advances'), 'currency_id' => $currencyId, 'credit' => $receipts,
                    ...$dims, 'party_id' => $profile->client_party_id, 'memo' => 'دفعات العميل حتى تاريخ البدء'];
            }
            foreach ($row['costs'] ?? [] as $j => $cost) {
                $category = ProjectCostCategory::query()->find($cost['project_cost_category_id']);
                if ($category === null || ! $category->is_active) {
                    throw ValidationException::withMessages(["projects.$i.costs.$j.project_cost_category_id" => ['تصنيف التكلفة غير موجود أو غير فعّال.']]);
                }
                $amount = Money::of($cost['amount']);
                if (Money::isPositive($amount)) {
                    $lines[] = ['account_id' => $this->roles->id('project_costs'), 'currency_id' => $currencyId, 'debit' => $amount, ...$dims,
                        'project_cost_category_id' => $category->id, 'memo' => 'تكاليف حتى تاريخ البدء: '.$category->name];
                }
            }
            $billed = Money::of($row['billed'] ?? '0');
            if (Money::isPositive($billed)) {
                if ($profile->contract_type === 'internal') {
                    throw ValidationException::withMessages(["projects.$i.billed" => ['المشروع داخلي ولا تُصدر له مطالبات.']]);
                }
                if ($currencyId !== $profile->contract_currency_id) {
                    throw ValidationException::withMessages(["projects.$i.billed" => ['المطالب به يُدخل بعملة العقد؛ أدخله في سطر بعملة العقد.']]);
                }
                $billings[] = ['project_id' => $project->id, 'party_id' => $profile->client_party_id, 'currency_id' => $currencyId, 'amount' => $billed];
            }
        }
    }

    private function agreementLines(array $rows, int $branchId, array &$lines, array &$extras): void
    {
        foreach ($rows as $i => $row) {
            $agreement = ContractorAgreement::query()->find($row['contractor_agreement_id']);
            if ($agreement === null || $agreement->status === 'cancelled' || $this->projects->find($agreement->project_id)?->branch_id !== $branchId) {
                throw ValidationException::withMessages(["agreements.$i.contractor_agreement_id" => ['الاتفاقية ملغاة أو ليست في مشروع من هذا الفرع.']]);
            }
            $paid = Money::of($row['paid']);
            if (! Money::isPositive($paid)) {
                continue;
            }
            $extras[count($lines)] = ['contractor_agreement_id' => $agreement->id];
            $lines[] = ['account_id' => $this->roles->id('project_costs'), 'currency_id' => $agreement->currency_id, 'debit' => $paid,
                'project_id' => $agreement->project_id, 'project_cost_category_id' => $agreement->project_cost_category_id,
                'party_id' => $agreement->contractor_party_id, 'memo' => 'المدفوع للمقاول حتى تاريخ البدء: '.$agreement->scope];
        }
    }

    /** @param  array{0: string, 1: string}  $roles  [owed to us (debit), we owe (credit)] */
    private function partyLines(array $rows, string $field, array $roles, array &$lines): void
    {
        foreach ($rows as $i => $row) {
            $party = Party::query()->find($row['party_id']);
            if ($party === null || ! $party->is_active) {
                throw ValidationException::withMessages(["$field.$i.party_id" => ['اختر جهة تعامل فعّالة.']]);
            }
            if ($field === 'partners' && ! Partner::query()->where('party_id', $party->id)->exists()
                && ! PartyRole::query()->where('party_id', $party->id)->where('role', 'partner')->exists()) {
                throw ValidationException::withMessages(["$field.$i.party_id" => ['الجهة ليست شريكاً.']]);
            }
            $amount = Money::of($row['amount']);
            if (! Money::isPositive($amount)) {
                continue;
            }
            $owedToUs = $row['direction'] === 'owed_to_us';
            $lines[] = ['account_id' => $this->roles->id($owedToUs ? $roles[0] : $roles[1]), 'currency_id' => $this->currencyId($row, "$field.$i.currency"),
                ($owedToUs ? 'debit' : 'credit') => $amount, 'party_id' => $party->id,
                'memo' => $field === 'partners' ? 'رصيد افتتاحي للحساب الجاري للشريك' : 'رصيد افتتاحي لذمة'];
        }
    }

    /** @return list<array<string, mixed>> the advances to create once the voucher posts */
    private function advanceLines(array $rows, array &$lines): array
    {
        $advances = [];
        foreach ($rows as $i => $row) {
            if ($this->advances->employee((int) $row['employee_id']) === null) {
                throw ValidationException::withMessages(["employee_advances.$i.employee_id" => ['الموظف غير موجود.']]);
            }
            $currencyId = $this->currencyId($row, "employee_advances.$i.currency");
            $currency = Currency::query()->findOrFail($currencyId);
            $remaining = Money::round(Money::of($row['remaining']), $currency->decimals);
            if (! empty($row['installments'])) {
                $schedule = array_map(fn ($inst) => ['due_month' => $inst['due_month'], 'amount' => Money::round(Money::of($inst['amount']), $currency->decimals)],
                    $row['installments']);
                if (Money::cmp(Money::sum(array_column($schedule, 'amount')), $remaining) !== 0) {
                    throw ValidationException::withMessages(["employee_advances.$i.installments" => ['مجموع الأقساط يجب أن يساوي المتبقي.']]);
                }
            } else {
                if (empty($row['installments_count']) || empty($row['start_month'])) {
                    throw ValidationException::withMessages(["employee_advances.$i.installments" => ['أدخل الأقساط أو عددها وشهر البداية.']]);
                }
                $schedule = array_map(fn ($amount, $n) => ['due_month' => EmployeeAdvanceService::addMonths($row['start_month'], $n), 'amount' => $amount],
                    $this->advances->split($remaining, (int) $row['installments_count'], $currency->decimals), range(0, (int) $row['installments_count'] - 1));
            }
            usort($schedule, fn ($a, $b) => strcmp($a['due_month'], $b['due_month']));
            $party = $this->advances->partyOf((int) $row['employee_id']);
            $lines[] = ['account_id' => $this->roles->id('employee_advances'), 'currency_id' => $currencyId, 'debit' => $remaining,
                'employee_id' => (int) $row['employee_id'], 'party_id' => $party?->id, 'memo' => 'سلفة موظف متبقية حتى تاريخ البدء'];
            $advances[] = ['employee_id' => (int) $row['employee_id'], 'party_id' => $party?->id, 'currency_id' => $currencyId, 'amount' => $remaining,
                'schedule' => $schedule, 'notes' => $row['notes'] ?? 'رصيد افتتاحي'];
        }

        return $advances;
    }

    /** @return list<int> */
    private function createAdvances(array $advances, Voucher $voucher, User $actor): array
    {
        $ids = [];
        foreach ($advances as $a) {
            $advance = EmployeeAdvance::create(['employee_id' => $a['employee_id'], 'party_id' => $a['party_id'], 'voucher_id' => $voucher->id,
                'amount' => $a['amount'], 'currency_id' => $a['currency_id'], 'installments_count' => count($a['schedule']),
                'start_month' => $a['schedule'][0]['due_month'], 'status' => 'active', 'notes' => $a['notes'], 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($a['schedule'] as $installment) {
                EmployeeAdvanceInstallment::create(['advance_id' => $advance->id, 'due_month' => $installment['due_month'], 'amount' => $installment['amount'],
                    'status' => 'pending', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            }
            $this->audit->log('finance.advance_created', $advance, null, $advance->getAttributes(), $actor->id);
            $ids[] = $advance->id;
        }

        return $ids;
    }

    /** @return list<int> custodies opened */
    private function custodyLines(array $rows, int $branchId, string $date, User $actor, array &$lines, array &$extras): array
    {
        $ids = [];
        foreach ($rows as $i => $row) {
            $holder = Party::query()->find($row['holder_party_id']);
            if ($holder === null || ! $holder->is_active) {
                throw ValidationException::withMessages(["custodies.$i.holder_party_id" => ['اختر جهة تعامل فعّالة.']]);
            }
            $projectId = isset($row['project_id']) ? (int) $row['project_id'] : null;
            if ($projectId !== null && $this->projects->find($projectId)?->branch_id !== $branchId) {
                throw ValidationException::withMessages(["custodies.$i.project_id" => ['المشروع ليس في هذا الفرع.']]);
            }
            $currencyId = $this->currencyId($row, "custodies.$i.currency");
            if ($this->custodies->openFor($holder->id, $projectId, $currencyId) !== null) {
                throw ValidationException::withMessages(["custodies.$i.holder_party_id" => ["لدى {$holder->name} عهدة مفتوحة بهذه العملة على المشروع نفسه."]]);
            }
            $balance = Money::of($row['balance']);
            if (! Money::isPositive($balance)) {
                continue;
            }
            $custody = Custody::create(['holder_party_id' => $holder->id, 'project_id' => $projectId, 'currency_id' => $currencyId, 'branch_id' => $branchId,
                'status' => 'open', 'opened_on' => $row['opened_on'] ?? $date, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $extras[count($lines)] = ['custody_id' => $custody->id];
            $lines[] = ['account_id' => $this->roles->id('custody'), 'currency_id' => $currencyId, 'debit' => $balance, 'party_id' => $holder->id,
                'project_id' => $projectId, 'memo' => 'عهدة مفتوحة حتى تاريخ البدء'];
            $ids[] = $custody->id;
        }

        return $ids;
    }

    /** A signed amount as a debit (positive) or credit (negative). */
    private function side(string $amount): array
    {
        return Money::isNegative($amount) ? ['credit' => Money::abs($amount)] : ['debit' => $amount];
    }
}
