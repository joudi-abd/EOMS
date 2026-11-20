<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Projects\BudgetMonitor;
use App\Modules\Finance\Services\Projects\ProjectAccess;
use App\Modules\Finance\Services\Projects\ProjectFinanceService;
use App\Modules\Finance\Services\Projects\ProjectProfitability;
use App\Modules\Finance\Services\Projects\ProjectStatementService;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Builder;

/**
 * Project reports on top of the project-finance services (phase 5): T8 summary, T9 statement, T10 budget against
 * actual (over-budget first), T11 profitability ranking (gross and net after loaded costs). Their reach is project
 * visibility (`finance.projects.view`: a project manager sees the projects they manage). T13 contractor balances across
 * projects follows the `finance.reports` branch reach.
 */
class ProjectReports
{
    /** Figures of a T8 row converted for the consolidated view. */
    private const SUMMARY_FIGURES = ['receipts', 'costs', 'remaining', 'fee', 'revenue', 'profit', 'client_balance'];

    public function __construct(private ProjectAccess $access, private ProjectFinanceService $finance, private ProjectProfitability $profitability,
        private ProjectStatementService $statements, private BudgetMonitor $budget, private SystemAccounts $roles) {}

    /**
     * T8: ProjectFinanceService::summary rows (in each project's contract currency), totals per contract currency and,
     * consolidated, each row and the total in the base currency.
     *
     * @param  array<string, mixed>  $filters  status, client_party_id, contract_type
     */
    public function summary(ReportFrame $frame, User $user, array $filters): array
    {
        $rows = $this->finance->summary([...$filters, 'branch_id' => $frame->branchId], $user, $frame->to);
        $profiles = ProjectProfile::query()->whereIn('project_id', array_column($rows, 'project_id'))->pluck('contract_currency_id', 'project_id');
        $base = $frame->consolidated ? $this->historicalFigures(array_column($rows, 'project_id'), $frame->to) : [];
        $totals = [];
        $consolidated = array_fill_keys(self::SUMMARY_FIGURES, '0');
        foreach ($rows as $i => $row) {
            $totals[$row['currency']] ??= ['currency' => $row['currency'], 'projects' => 0, ...array_fill_keys(self::SUMMARY_FIGURES, '0')];
            $totals[$row['currency']]['projects']++;
            foreach (self::SUMMARY_FIGURES as $figure) {
                $totals[$row['currency']][$figure] = Money::add($totals[$row['currency']][$figure], $row[$figure] ?? '0');
            }
            if ($frame->consolidated) {
                $currencyId = (int) $profiles[$row['project_id']];
                $b = $base[$row['project_id']] ?? null;
                $converted = [];
                foreach (self::SUMMARY_FIGURES as $figure) {
                    $converted[$figure] = $row[$figure] === null ? null
                        : $frame->consolidator->value($row[$figure], $figure === 'fee' ? null : ($b[$figure] ?? '0'), $currencyId);
                    $consolidated[$figure] = Money::add($consolidated[$figure], $converted[$figure] ?? '0');
                }
                $rows[$i]['base'] = $converted;
            }
        }
        ksort($totals);
        $result = ['date' => $frame->to, 'projects' => $rows, 'totals' => array_values($totals)];
        if ($frame->consolidated) {
            $result['consolidated'] = $consolidated;
        }

        return [...$frame->header(), ...$result];
    }

    /** T9: the project statement (internal or the client's copy). */
    public function statement(User $user, int $projectId, string $variant, bool $hideInternal, ?string $date): array
    {
        return $this->statements->build($this->access->readableProfile($user, $projectId), $variant, $hideInternal, $date);
    }

    /**
     * T10: budget lines of the visible projects with the actual, the percentage and the level; exceeded lines first,
     * then warnings, then by percentage.
     *
     * @param  array<string, mixed>  $filters  project_id, status, project_cost_category_id
     */
    public function budgetVsActual(ReportFrame $frame, User $user, array $filters): array
    {
        $projects = $this->projects($user, $frame, $filters)->get(['id', 'name', 'status', 'branch_id'])->keyBy('id');
        $lines = ProjectBudgetLine::query()->with('category:id,name,system_key,is_billable')->whereIn('project_id', $projects->keys())
            ->when($filters['project_cost_category_id'] ?? null, fn ($q, $id) => $q->where('project_cost_category_id', $id))
            ->when($frame->currency, fn ($q, $c) => $q->where('currency_id', $c->id))->get();
        $codes = Currency::query()->pluck('code', 'id');
        $profiles = ProjectProfile::query()->whereIn('project_id', $projects->keys())->pluck('code', 'project_id');
        $rank = ['exceeded' => 0, 'warning' => 1, 'none' => 2];
        $rows = $lines->map(function (ProjectBudgetLine $line) use ($frame, $projects, $codes, $profiles) {
            $measure = $this->budget->measure($line, $frame->to);
            $budget = Money::of((string) $line->budget_amount);

            return ['budget_line_id' => $line->id, 'project_id' => $line->project_id, 'project_code' => $profiles[$line->project_id] ?? null,
                'project' => $projects[$line->project_id]->name, 'category_id' => $line->project_cost_category_id, 'category' => $line->category?->name,
                'currency' => $codes[$line->currency_id] ?? null, 'budget' => $budget, 'actual' => $measure['actual'] ?? null,
                'remaining' => $measure === null ? null : Money::sub($budget, $measure['actual']), 'pct' => $measure['pct'] ?? null,
                'level' => $measure['level'] ?? null, 'colour' => $measure['colour'] ?? null];
        })->sort(fn ($a, $b) => [$rank[$a['level'] ?? 'none'], -1 * (float) ($a['pct'] ?? 0), $a['project_id']]
            <=> [$rank[$b['level'] ?? 'none'], -1 * (float) ($b['pct'] ?? 0), $b['project_id']])->values()->all();

        return [...$frame->header(), 'date' => $frame->to, 'lines' => $rows,
            'over_budget_projects' => array_values(array_unique(array_column(array_filter($rows, fn ($r) => $r['level'] === 'exceeded'), 'project_id')))];
    }

    /**
     * T11: every visible project's profitability (§6.2) on `to`, ranked by net margin in the base currency at the
     * report date (projects keep different contract currencies), with the gross and net margin percentages.
     *
     * @param  array<string, mixed>  $filters  status, contract_type
     */
    public function profitabilityRanking(ReportFrame $frame, User $user, array $filters): array
    {
        $projects = $this->projects($user, $frame, $filters)->get(['id', 'name', 'status', 'branch_id'])->keyBy('id');
        $profiles = ProjectProfile::query()->whereIn('project_id', $projects->keys())
            ->when($filters['contract_type'] ?? null, fn ($q, $t) => $q->where('contract_type', $t))->orderBy('code')->get();
        $ranking = $frame->consolidator ?? app(ReportFrames::class)->consolidator($frame->to, 'report_date');
        $rows = [];
        foreach ($profiles as $profile) {
            $p = $this->profitability->for($profile, $frame->to);
            $pct = fn (string $v) => Money::isPositive($p['revenue']) ? Money::div(Money::mul($v, '100', 8), $p['revenue'], 2) : null;
            $rows[] = ['project_id' => $profile->project_id, 'code' => $profile->code, 'name' => $projects[$profile->project_id]->name,
                'status' => $projects[$profile->project_id]->status, 'branch_id' => $projects[$profile->project_id]->branch_id,
                'contract_type' => $profile->contract_type, 'currency' => $p['currency'], 'revenue' => $p['revenue'], 'direct_costs' => $p['direct_costs'],
                'loaded_costs' => $p['loaded_costs'], 'gross_margin' => $p['gross_margin'], 'gross_margin_pct' => $pct($p['gross_margin']),
                'net_margin' => $p['net_margin'], 'net_margin_pct' => $pct($p['net_margin']), 'fee' => $p['fee'],
                'net_margin_base' => $ranking->atReportDate($p['net_margin'], $profile->contract_currency_id),
                'gross_margin_base' => $ranking->atReportDate($p['gross_margin'], $profile->contract_currency_id)];
        }
        usort($rows, fn ($a, $b) => Money::cmp($b['net_margin_base'], $a['net_margin_base']) ?: $a['project_id'] <=> $b['project_id']);
        foreach ($rows as $i => $row) {
            $rows[$i]['rank'] = $i + 1;
        }

        return [...$frame->header(), 'date' => $frame->to, 'ranking_basis' => $ranking->header(), 'projects' => $rows,
            'totals' => ['gross_margin_base' => Money::sum(array_column($rows, 'gross_margin_base')), 'net_margin_base' => Money::sum(array_column($rows, 'net_margin_base'))]];
    }

    /**
     * T13: per contractor and currency across projects (in the `finance.reports` reach): agreements (count, net value
     * of the not-cancelled ones), paid on them (gross, advances deducted included), paid without an agreement
     * (payments_only or cancelled agreements), the remaining (net value − paid on agreements), the open contractor
     * advances and the contractors' payables balance (credit = we owe).
     */
    public function contractorBalances(ReportFrame $frame, ?int $partyId = null): array
    {
        $projectIds = $frame->branchIds === null ? null : Project::query()->whereIn('branch_id', $frame->branchIds)->pluck('id')->all();
        $agreements = ContractorAgreement::query()->where('status', '!=', 'cancelled')
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->when($partyId, fn ($q, $id) => $q->where('contractor_party_id', $id))
            ->when($frame->currency, fn ($q, $c) => $q->where('currency_id', $c->id))
            ->groupBy('contractor_party_id', 'currency_id')
            ->selectRaw('contractor_party_id, currency_id, COUNT(*) as agreements, SUM(agreed_value) as agreed_value, SUM(discount) as discount, SUM(net_value) as net_value,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_agreements', ['open'])
            ->get();
        $lines = fn (string $role) => $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()
            ->where('fin_journal_lines.account_id', $this->roles->mappedId($role) ?? 0)->where('fin_journal_lines.entry_date', '<=', $frame->to)));
        $costs = $lines('project_costs')->leftJoin('fin_contractor_agreements as a', 'a.id', '=', 'fin_journal_lines.contractor_agreement_id')
            ->whereNotNull('fin_journal_lines.party_id')
            ->whereIn('fin_journal_lines.party_id', Party::query()->select('fin_parties.id')->join('fin_party_roles as r', 'r.party_id', '=', 'fin_parties.id')
                ->where('r.role', 'contractor'))
            ->when($partyId, fn ($q, $id) => $q->where('fin_journal_lines.party_id', $id))
            ->groupBy('fin_journal_lines.party_id', 'fin_journal_lines.currency_id')
            ->selectRaw("fin_journal_lines.party_id, fin_journal_lines.currency_id,
                SUM(CASE WHEN a.id IS NOT NULL AND a.status <> 'cancelled' THEN fin_journal_lines.debit - fin_journal_lines.credit ELSE 0 END) as on_agreements,
                SUM(CASE WHEN a.id IS NULL OR a.status = 'cancelled' THEN fin_journal_lines.debit - fin_journal_lines.credit ELSE 0 END) as without_agreement")
            ->get();
        $balance = fn (string $role) => $lines($role)->whereNotNull('fin_journal_lines.party_id')
            ->when($partyId, fn ($q, $id) => $q->where('fin_journal_lines.party_id', $id))
            ->groupBy('fin_journal_lines.party_id', 'fin_journal_lines.currency_id')
            ->selectRaw('fin_journal_lines.party_id, fin_journal_lines.currency_id, SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as net')
            ->get()->keyBy(fn ($r) => $r->party_id.'|'.$r->currency_id);
        $advances = $balance('contractor_advances');
        $payables = $balance('payables_contractors');
        $contractors = Party::query()->whereHas('roles', fn ($q) => $q->where('role', 'contractor'))->pluck('name', 'id');

        $rows = [];
        $row = function (int $party, int $currency) use (&$rows) {
            return $rows["$party|$currency"] ??= ['party_id' => $party, 'currency_id' => $currency, 'agreements' => 0, 'open_agreements' => 0,
                'agreed_value' => '0', 'discount' => '0', 'net_value' => '0', 'paid_on_agreements' => '0', 'paid_without_agreement' => '0'];
        };
        foreach ($agreements as $a) {
            $r = $row((int) $a->contractor_party_id, (int) $a->currency_id);
            $rows["{$a->contractor_party_id}|{$a->currency_id}"] = [...$r, 'agreements' => (int) $a->agreements, 'open_agreements' => (int) $a->open_agreements,
                'agreed_value' => Sums::num($a->agreed_value), 'discount' => Sums::num($a->discount), 'net_value' => Sums::num($a->net_value)];
        }
        foreach ($costs as $c) {
            $r = $row((int) $c->party_id, (int) $c->currency_id);
            $rows["{$c->party_id}|{$c->currency_id}"] = [...$r, 'paid_on_agreements' => Sums::num($c->on_agreements),
                'paid_without_agreement' => Sums::num($c->without_agreement)];
        }
        foreach ($advances->keys()->merge($payables->keys()) as $key) {
            [$party, $currency] = array_map('intval', explode('|', $key));
            if ($contractors->has($party)) {
                $row($party, $currency);
            }
        }
        $codes = Currency::query()->pluck('code', 'id');
        $result = [];
        $totals = [];
        foreach ($rows as $key => $r) {
            $r['contractor'] = $contractors[$r['party_id']] ?? Party::query()->whereKey($r['party_id'])->value('name');
            $r['currency'] = $codes[$r['currency_id']] ?? null;
            $r['remaining'] = Money::sub($r['net_value'], $r['paid_on_agreements']);
            $r['advances_outstanding'] = Sums::num($advances->get($key)?->net);
            $r['payables'] = Money::neg(Sums::num($payables->get($key)?->net));
            $result[] = $r;
            $totals[$r['currency']] ??= ['currency' => $r['currency'], 'net_value' => '0', 'paid_on_agreements' => '0', 'paid_without_agreement' => '0',
                'remaining' => '0', 'advances_outstanding' => '0', 'payables' => '0'];
            foreach (['net_value', 'paid_on_agreements', 'paid_without_agreement', 'remaining', 'advances_outstanding', 'payables'] as $k) {
                $totals[$r['currency']][$k] = Money::add($totals[$r['currency']][$k], $r[$k]);
            }
        }
        usort($result, fn ($a, $b) => [$a['contractor'], $a['currency']] <=> [$b['contractor'], $b['currency']]);
        ksort($totals);

        return [...$frame->header(), 'as_of' => $frame->to, 'contractors' => $result, 'totals' => array_values($totals)];
    }

    /** Visible projects (finance.projects.view reach) narrowed by the request's branch, project and status. */
    private function projects(User $user, ReportFrame $frame, array $filters): Builder
    {
        return $this->access->visibleProjects($user)
            ->when($frame->branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    /**
     * Historical base (USD) sums per project, for the consolidated summary in `historical` mode: receipts (client
     * advances not from billings), costs, revenue and what follows from them.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array<string, string>>
     */
    private function historicalFigures(array $projectIds, string $date): array
    {
        if ($projectIds === []) {
            return [];
        }
        $billingEntries = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->where('type', 'project_billing'));
        $sum = fn (string $role, bool $receipts = false) => JournalLine::query()->where('account_id', $this->roles->mappedId($role) ?? 0)
            ->whereIn('project_id', $projectIds)->where('entry_date', '<=', $date)
            ->when($receipts, fn ($q) => $q->whereNotIn('journal_entry_id', $billingEntries))
            ->groupBy('project_id')->selectRaw('project_id, SUM(base_debit) - SUM(base_credit) as base_net')->pluck('base_net', 'project_id');
        $receipts = $sum('client_advances', true);
        $costs = $sum('project_costs');
        $revenue = $sum('project_revenue');
        $figures = [];
        foreach ($projectIds as $id) {
            $r = Money::neg(Sums::num($receipts[$id] ?? null));
            $c = Sums::num($costs[$id] ?? null);
            $v = Money::neg(Sums::num($revenue[$id] ?? null));
            $figures[$id] = ['receipts' => $r, 'costs' => $c, 'remaining' => Money::sub($r, $c), 'revenue' => $v, 'profit' => Money::sub($v, $c),
                'client_balance' => Money::sub($r, $v)];
        }

        return $figures;
    }
}
