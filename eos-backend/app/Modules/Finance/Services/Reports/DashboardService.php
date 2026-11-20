<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The general manager's dashboard (spec §10.1) within the `finance.reports` branch reach, built from a handful of
 * aggregate queries (no per-box or per-project loops over the ledger). Consolidated figures follow the company rate
 * mode on today's date and list the rates used.
 */
class DashboardService
{
    /** A box is "near its limit" once this share of its negative limit is used (as on the boxes board). */
    private const NEAR_LIMIT_SHARE = '0.8';

    /** Box-to-box movements are not income or spending. */
    private const INTERNAL_TYPES = ['transfer', 'exchange'];

    /** @var Collection<int, Currency> */
    private Collection $currencies;

    public function __construct(private FinanceScope $scope, private ReportFrames $frames, private SystemAccounts $roles,
        private CustodyService $custodies, private ExchangeRateService $rates) {}

    public function build(User $user): array
    {
        $branchIds = $this->scope->branchIds($user, 'finance.reports');
        $today = now()->toDateString();
        $c = $this->frames->consolidator($today);
        $this->currencies = Currency::query()->get()->keyBy('id');
        $boxes = CashBox::query()->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->get()->keyBy('account_id');
        $boxSums = JournalLine::query()->whereIn('account_id', $boxes->keys())->groupBy('account_id', 'currency_id')
            ->selectRaw('account_id, currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')->get();

        return [
            'date' => $today,
            'liquidity' => $this->liquidity($boxSums, $c),
            'box_alerts' => $this->boxAlerts($boxes, $boxSums),
            'projects' => $this->projects($branchIds, $today, $c),
            'cash_flow' => $this->cashFlow($boxes->keys()->all(), $c),
            'contractors' => $this->contractors($branchIds, $today, $c),
            'clients' => $this->roleBalance('receivables_clients', $branchIds, false, $c),
            'custodies' => $this->overdueCustodies($branchIds, $c),
            'cash_counts_pending' => $this->pendingCounts($branchIds),
            'partner_drawings' => $this->drawings($branchIds, $c),
            'consolidation' => $c->header(),
        ];
    }

    private function liquidity(Collection $boxSums, Consolidator $c): array
    {
        $per = [];
        foreach ($boxSums->groupBy('currency_id') as $currencyId => $rows) {
            $balance = Money::sum($rows->map(fn ($r) => Sums::num($r->net)));
            $per[] = ['currency' => $this->currencies[$currencyId]->code, 'balance' => $balance,
                'balance_base' => $c->value($balance, Money::sum($rows->map(fn ($r) => Sums::num($r->base_net))), (int) $currencyId)];
        }
        usort($per, fn ($a, $b) => strcmp($a['currency'], $b['currency']));

        return ['total_base' => Money::sum(array_column($per, 'balance_base')), 'currencies' => $per];
    }

    /** Boxes below zero, or with a negative limit and at least 80% of it used. */
    private function boxAlerts(Collection $boxes, Collection $boxSums): array
    {
        $settings = CashBoxCurrency::query()->whereIn('cash_box_id', $boxes->pluck('id'))->get()->keyBy(fn ($s) => $s->cash_box_id.'|'.$s->currency_id);
        $alerts = [];
        foreach ($boxSums as $sum) {
            $box = $boxes[$sum->account_id];
            $balance = Sums::num($sum->net);
            if (! Money::isNegative($balance)) {
                continue;
            }
            $setting = $settings->get($box->id.'|'.$sum->currency_id);
            $limit = $setting?->negative_limit === null ? null : Money::of((string) $setting->negative_limit);
            $near = $setting?->allow_negative && $limit !== null && Money::cmp(Money::abs($balance), Money::mul($limit, self::NEAR_LIMIT_SHARE)) >= 0;
            $alerts[] = ['cash_box_id' => $box->id, 'box' => $box->name, 'branch_id' => $box->branch_id, 'currency' => $this->currencies[$sum->currency_id]->code,
                'balance' => $balance, 'negative_limit' => $limit, 'negative' => true, 'near_limit' => $near];
        }
        usort($alerts, fn ($a, $b) => [$a['box'], $a['currency']] <=> [$b['box'], $b['currency']]);

        return $alerts;
    }

    /**
     * Net profit (revenue − all project costs, §6.2) per project in its contract currency from two grouped queries,
     * ranked in the base currency; and the projects with a budget line over 100%.
     *
     * @param  list<int>|null  $branchIds
     */
    private function projects(?array $branchIds, string $today, Consolidator $c): array
    {
        $projects = Project::query()->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->get(['id', 'name', 'status'])->keyBy('id');
        $profiles = ProjectProfile::query()->whereIn('project_id', $projects->keys())->get(['project_id', 'code', 'contract_currency_id'])->keyBy('project_id');
        $sums = JournalLine::query()->whereIn('account_id', array_filter([$this->roles->mappedId('project_costs'), $this->roles->mappedId('project_revenue')]))
            ->whereIn('project_id', $profiles->keys())->groupBy('project_id', 'account_id', 'currency_id')
            ->selectRaw('project_id, account_id, currency_id, SUM(credit) - SUM(debit) as net, SUM(base_credit) - SUM(base_debit) as base_net')->get();
        $net = [];
        $rateCache = [];
        foreach ($sums as $s) {
            $profile = $profiles[$s->project_id];
            $contract = $this->currencies[$profile->contract_currency_id];
            if ((int) $s->currency_id === $contract->id) {
                $amount = Sums::num($s->net);
            } else {
                $rate = $rateCache[$contract->id] ??= ($contract->is_base ? '1' : ($this->rates->rateToBaseOnOrBefore($contract->code, $today)['rate'] ?? '0'));
                $amount = Money::mul(Sums::num($s->base_net), $rate);
            }
            $net[$s->project_id] = Money::add($net[$s->project_id] ?? '0', $amount);
        }
        $rows = [];
        foreach ($net as $projectId => $amount) {
            $profile = $profiles[$projectId];
            $rows[] = ['project_id' => $projectId, 'code' => $profile->code, 'name' => $projects[$projectId]->name, 'status' => $projects[$projectId]->status,
                'currency' => $this->currencies[$profile->contract_currency_id]->code, 'net_profit' => Money::round($amount, $this->currencies[$profile->contract_currency_id]->decimals),
                'net_profit_base' => $c->atReportDate($amount, $profile->contract_currency_id)];
        }
        usort($rows, fn ($a, $b) => Money::cmp($b['net_profit_base'], $a['net_profit_base']));
        $over = ProjectBudgetLine::query()->whereIn('project_id', $projects->keys())->where('alert_level', 'exceeded')
            ->groupBy('project_id')->selectRaw('project_id, COUNT(*) as exceeded_lines')->get()
            ->map(fn ($r) => ['project_id' => (int) $r->project_id, 'code' => $profiles[$r->project_id]->code ?? null, 'name' => $projects[$r->project_id]->name ?? null,
                'exceeded_lines' => (int) $r->exceeded_lines])->values()->all();

        return ['top_profit' => array_slice($rows, 0, 5), 'over_budget' => $over];
    }

    /**
     * Money into and out of the reachable boxes this month and last month, per currency, without box-to-box transfers
     * and exchanges.
     *
     * @param  list<int>  $boxAccounts
     */
    private function cashFlow(array $boxAccounts, Consolidator $c): array
    {
        $thisMonth = CarbonImmutable::now()->startOfMonth();
        $lastMonth = $thisMonth->subMonth();
        $internal = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->whereIn('type', self::INTERNAL_TYPES));
        $rows = JournalLine::query()->whereIn('account_id', $boxAccounts)->whereNotIn('journal_entry_id', $internal)
            ->whereBetween('entry_date', [$lastMonth->toDateString(), now()->toDateString()])->groupBy('currency_id')
            ->selectRaw('currency_id,
                SUM(CASE WHEN entry_date >= ? THEN debit ELSE 0 END) as in_now, SUM(CASE WHEN entry_date >= ? THEN credit ELSE 0 END) as out_now,
                SUM(CASE WHEN entry_date < ? THEN debit ELSE 0 END) as in_last, SUM(CASE WHEN entry_date < ? THEN credit ELSE 0 END) as out_last,
                SUM(CASE WHEN entry_date >= ? THEN base_debit ELSE 0 END) as b_in_now, SUM(CASE WHEN entry_date >= ? THEN base_credit ELSE 0 END) as b_out_now,
                SUM(CASE WHEN entry_date < ? THEN base_debit ELSE 0 END) as b_in_last, SUM(CASE WHEN entry_date < ? THEN base_credit ELSE 0 END) as b_out_last',
                array_fill(0, 8, $thisMonth->toDateString()))
            ->get();
        $per = [];
        $base = ['this_month' => ['in' => '0', 'out' => '0'], 'last_month' => ['in' => '0', 'out' => '0']];
        foreach ($rows as $r) {
            $id = (int) $r->currency_id;
            $row = ['currency' => $this->currencies[$id]->code,
                'this_month' => ['in' => Sums::num($r->in_now), 'out' => Sums::num($r->out_now)],
                'last_month' => ['in' => Sums::num($r->in_last), 'out' => Sums::num($r->out_last)]];
            foreach (['this_month' => ['in' => 'b_in_now', 'out' => 'b_out_now'], 'last_month' => ['in' => 'b_in_last', 'out' => 'b_out_last']] as $period => $cols) {
                $row[$period]['net'] = Money::sub($row[$period]['in'], $row[$period]['out']);
                foreach ($cols as $k => $col) {
                    $base[$period][$k] = Money::add($base[$period][$k], $c->value($row[$period][$k], Sums::num($r->{$col}), $id));
                }
            }
            $per[] = $row;
        }
        foreach ($base as $period => $v) {
            $base[$period]['net'] = Money::sub($v['in'], $v['out']);
        }
        usort($per, fn ($a, $b) => strcmp($a['currency'], $b['currency']));

        return ['this_month' => $thisMonth->format('Y-m'), 'last_month' => $lastMonth->format('Y-m'), 'currencies' => $per, 'consolidated' => $base];
    }

    /**
     * What we owe contractors: their payables balance plus what remains on open agreements (net value − paid).
     *
     * @param  list<int>|null  $branchIds
     */
    private function contractors(?array $branchIds, string $today, Consolidator $c): array
    {
        $projectIds = $branchIds === null ? null : Project::query()->whereIn('branch_id', $branchIds)->pluck('id')->all();
        $agreements = ContractorAgreement::query()->where('status', 'open')->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds));
        $values = (clone $agreements)->groupBy('currency_id')->selectRaw('currency_id, SUM(net_value) as net_value')->pluck('net_value', 'currency_id');
        $paid = JournalLine::query()->where('account_id', $this->roles->mappedId('project_costs') ?? 0)
            ->whereIn('contractor_agreement_id', (clone $agreements)->select('id'))->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(debit) - SUM(credit) as net')->pluck('net', 'currency_id');
        $remaining = [];
        foreach ($values as $currencyId => $value) {
            $amount = Money::max('0', Money::sub(Sums::num($value), Sums::num($paid[$currencyId] ?? null)));
            $remaining[] = ['currency' => $this->currencies[$currencyId]->code, 'amount' => $amount, 'amount_base' => $c->atReportDate($amount, (int) $currencyId)];
        }

        return ['payables' => $this->roleBalance('payables_contractors', $branchIds, true, $c), 'agreements_remaining' => $remaining,
            'agreements_remaining_base' => Money::sum(array_column($remaining, 'amount_base'))];
    }

    /**
     * Balance of a role account per currency in the reach; `$credit` = shown as credit − debit.
     *
     * @param  list<int>|null  $branchIds
     */
    private function roleBalance(string $role, ?array $branchIds, bool $credit, Consolidator $c): array
    {
        $rows = JournalLine::query()->where('account_id', $this->roles->mappedId($role) ?? 0)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')->get();
        $per = [];
        foreach ($rows as $r) {
            $amount = $credit ? Money::neg(Sums::num($r->net)) : Sums::num($r->net);
            $base = $credit ? Money::neg(Sums::num($r->base_net)) : Sums::num($r->base_net);
            if (! Money::isZero($amount)) {
                $per[] = ['currency' => $this->currencies[$r->currency_id]->code, 'amount' => $amount, 'amount_base' => $c->value($amount, $base, (int) $r->currency_id)];
            }
        }

        return ['currencies' => $per, 'total_base' => Money::sum(array_column($per, 'amount_base'))];
    }

    /** @param  list<int>|null  $branchIds */
    private function overdueCustodies(?array $branchIds, Consolidator $c): array
    {
        $days = $this->custodies->overdueDays();
        $rows = Custody::query()->where('status', 'open')->where('opened_on', '<', now()->subDays($days)->toDateString())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->groupBy('currency_id')
            ->selectRaw('currency_id, COUNT(*) as custodies, SUM(balance) as balance')->get();
        $per = $rows->map(fn ($r) => ['currency' => $this->currencies[$r->currency_id]->code, 'count' => (int) $r->custodies,
            'balance' => Sums::num($r->balance), 'balance_base' => $c->atReportDate(Sums::num($r->balance), (int) $r->currency_id)])->values()->all();

        return ['overdue_days' => $days, 'count' => array_sum(array_column($per, 'count')), 'currencies' => $per];
    }

    /** @param  list<int>|null  $branchIds */
    private function pendingCounts(?array $branchIds): array
    {
        $counts = CashCount::query()->where('status', 'submitted')->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->with('cashBox:id,name')->orderBy('count_date')->get(['id', 'cash_box_id', 'count_date', 'counted_by']);

        return ['count' => $counts->count(), 'items' => $counts->map(fn (CashCount $count) => ['id' => $count->id, 'cash_box_id' => $count->cash_box_id,
            'box' => $count->cashBox?->name, 'count_date' => $count->count_date->toDateString(), 'counted_by' => $count->counted_by])->all()];
    }

    /** Approved partner drawings this month (D15): personal (partners' current account) and those booked as company expenses. */
    private function drawings(?array $branchIds, Consolidator $c): array
    {
        $rows = Voucher::query()->where('type', 'payment')->where('purpose', 'partner_drawing')->where('status', 'approved')
            ->whereBetween('voucher_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->groupBy('currency_id', 'classification')
            ->selectRaw('currency_id, classification, COUNT(*) as vouchers, SUM(amount) as amount')->get();
        $per = [];
        foreach ($rows as $r) {
            $code = $this->currencies[$r->currency_id]->code;
            $per[$code] ??= ['currency' => $code, 'personal' => '0', 'company_expense' => '0', 'vouchers' => 0];
            $per[$code][$r->classification === 'partner_personal' ? 'personal' : 'company_expense'] = Sums::num($r->amount);
            $per[$code]['vouchers'] += (int) $r->vouchers;
        }
        ksort($per);
        $total = '0';
        foreach ($per as $code => $row) {
            $per[$code]['personal_base'] = $c->atReportDate($row['personal'], $this->currencies->firstWhere('code', $code)->id);
            $total = Money::add($total, $per[$code]['personal_base']);
        }

        return ['month' => now()->format('Y-m'), 'currencies' => array_values($per), 'personal_total_base' => $total];
    }
}
