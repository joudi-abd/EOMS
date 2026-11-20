<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Models\CashCountLine;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Treasury\BoxStatementService;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;

/**
 * Treasury reports: T1 box balances on a day, T2 box movement (the box statement with a consolidated header), T21
 * exchanges against the bulletin with the fx_position result, T22 cash-count history. Boxes are reached through their
 * branch (the report permission's reach).
 */
class TreasuryReports
{
    public function __construct(private BoxStatementService $statements, private SystemAccounts $roles, private ExchangeRateService $rates) {}

    /**
     * T1: every reachable box with its balance in each currency at the end of `to`, the totals per currency and, when
     * consolidated, each box and the whole in the base currency.
     */
    public function boxBalances(ReportFrame $frame): array
    {
        $boxes = $frame->scopeBranches(CashBox::query(), 'branch_id')->orderBy('name')->orderBy('id')->get();
        $enabled = CashBoxCurrency::query()->whereIn('cash_box_id', $boxes->pluck('id'))->get()->groupBy('cash_box_id');
        $sums = $frame->scopeCurrency(JournalLine::query()->whereIn('account_id', $boxes->pluck('account_id'))->where('entry_date', '<=', $frame->to))
            ->groupBy('account_id', 'currency_id')
            ->selectRaw('account_id, currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')
            ->get()->groupBy('account_id');
        $currencies = Currency::query()->get()->keyBy('id');
        $c = $frame->consolidator;
        $totals = [];
        $rows = [];
        $consolidatedTotal = '0';
        foreach ($boxes as $box) {
            $byCurrency = [];
            foreach ($enabled->get($box->id, collect()) as $setting) {
                $byCurrency[$setting->currency_id] = ['net' => '0', 'base' => '0'];
            }
            foreach ($sums->get($box->account_id, collect()) as $sum) {
                $byCurrency[(int) $sum->currency_id] = ['net' => Sums::num($sum->net), 'base' => Sums::num($sum->base_net)];
            }
            if ($frame->currency !== null) {
                $byCurrency = array_intersect_key($byCurrency, [$frame->currency->id => true]);
            }
            $balances = [];
            $boxBase = '0';
            foreach ($byCurrency as $currencyId => $amounts) {
                $currency = $currencies[$currencyId];
                $balance = Money::round($amounts['net'], $currency->decimals);
                $row = ['currency_id' => $currency->id, 'code' => $currency->code, 'balance' => $balance, 'negative' => Money::isNegative($balance)];
                if ($frame->consolidated) {
                    $row['balance_base'] = $c->value($balance, $amounts['base'], $currency->id);
                    $boxBase = Money::add($boxBase, $row['balance_base']);
                }
                $balances[] = $row;
                $totals[$currency->code] ??= ['currency_id' => $currency->id, 'code' => $currency->code, 'balance' => '0'];
                $totals[$currency->code]['balance'] = Money::add($totals[$currency->code]['balance'], $balance);
                if ($frame->consolidated) {
                    $totals[$currency->code]['balance_base'] = Money::add($totals[$currency->code]['balance_base'] ?? '0', $row['balance_base']);
                }
            }
            if ($balances === [] && ! $box->is_active) {
                continue;
            }
            usort($balances, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $row = ['id' => $box->id, 'name' => $box->name, 'type' => $box->type, 'branch_id' => $box->branch_id, 'is_active' => $box->is_active,
                'balances' => $balances];
            if ($frame->consolidated) {
                $row['total_base'] = $boxBase;
                $consolidatedTotal = Money::add($consolidatedTotal, $boxBase);
            }
            $rows[] = $row;
        }
        ksort($totals);
        $result = ['as_of' => $frame->to, 'boxes' => $rows, 'totals' => array_values($totals)];
        if ($frame->consolidated) {
            $result['consolidated'] = ['total' => $consolidatedTotal];
        }

        return [...$frame->header(), ...$result];
    }

    /** T2: the box statement (BoxStatementService) with, when consolidated, opening, in, out and closing in the base currency. */
    public function boxMovement(ReportFrame $frame, CashBox $box): array
    {
        $statement = $this->statements->statement($box, $frame->from, $frame->to, $frame->currencyId());
        if ($frame->consolidated) {
            $c = $frame->consolidator;
            $base = JournalLine::query()->where('account_id', $box->account_id)->where('entry_date', '<=', $frame->to)->groupBy('currency_id')
                ->selectRaw('currency_id, SUM(CASE WHEN entry_date < ? THEN base_debit - base_credit ELSE 0 END) as opening,
                    SUM(CASE WHEN entry_date >= ? THEN base_debit ELSE 0 END) as base_in, SUM(CASE WHEN entry_date >= ? THEN base_credit ELSE 0 END) as base_out',
                    [$frame->from, $frame->from, $frame->from])
                ->get()->keyBy('currency_id');
            $totals = ['opening' => '0', 'total_in' => '0', 'total_out' => '0', 'closing' => '0'];
            foreach ($statement['currencies'] as $i => $block) {
                $b = $base->get($block['currency_id']);
                $opening = $c->value($block['opening'], Sums::num($b?->opening), $block['currency_id']);
                $in = $c->value($block['total_in'], Sums::num($b?->base_in), $block['currency_id']);
                $out = $c->value($block['total_out'], Sums::num($b?->base_out), $block['currency_id']);
                $block['base'] = ['opening' => $opening, 'total_in' => $in, 'total_out' => $out, 'closing' => Money::sub(Money::add($opening, $in), $out)];
                foreach ($totals as $key => $value) {
                    $totals[$key] = Money::add($value, $block['base'][$key]);
                }
                $statement['currencies'][$i] = $block;
            }
            $statement['consolidated'] = $totals;
        }

        return [...$frame->header(), ...$statement];
    }

    /**
     * T21: every exchange voucher in the period with its deal rate against the bulletin cross rate of its day, the
     * difference, and the voucher's effect on fx_position in the base currency; plus the fx_position balances at the
     * end of `to`: per currency, and in the base currency (= exchange gain or loss: a credit balance is a gain).
     */
    public function exchanges(ReportFrame $frame, ?int $boxId = null): array
    {
        $vouchers = $frame->scopeBranches(Voucher::query()->where('type', 'exchange')->whereIn('status', ['approved', 'reversed'])
            ->whereBetween('voucher_date', [$frame->from, $frame->to]), 'branch_id')
            ->when($boxId, fn ($q, $id) => $q->where(fn ($q) => $q->where('cash_box_id', $id)->orWhere('counter_cash_box_id', $id)))
            ->when($frame->currency, fn ($q, $cur) => $q->where(fn ($q) => $q->where('currency_id', $cur->id)->orWhere('counter_currency_id', $cur->id)))
            ->orderBy('voucher_date')->orderBy('id')->get();
        $fx = $this->roles->mappedId('fx_position') ?? 0;
        $effects = JournalLine::query()->where('account_id', $fx)->whereIn('journal_entry_id', $vouchers->pluck('journal_entry_id')->filter())
            ->groupBy('journal_entry_id')->selectRaw('journal_entry_id, SUM(base_debit) - SUM(base_credit) as base_net')->pluck('base_net', 'journal_entry_id');
        $codes = Currency::query()->pluck('code', 'id');
        $boxes = CashBox::query()->whereIn('id', $vouchers->pluck('cash_box_id')->merge($vouchers->pluck('counter_cash_box_id'))->filter()->unique())->pluck('name', 'id');
        $bulletins = [];
        $rows = [];
        $result = '0';
        foreach ($vouchers as $voucher) {
            $from = $codes[$voucher->currency_id] ?? null;
            $to = $codes[$voucher->counter_currency_id] ?? null;
            $date = $voucher->voucher_date->toDateString();
            $bulletin = $bulletins["$from|$to|$date"] ??= ($from && $to ? $this->rates->crossRateOnOrBefore($from, $to, $date) : null);
            $rate = Money::of((string) $voucher->rate);
            $difference = $bulletin === null ? null : Money::sub($rate, Money::round($bulletin, Money::RATE_SCALE), Money::RATE_SCALE);
            $fxNet = $voucher->status === 'approved' ? Sums::num($effects[$voucher->journal_entry_id] ?? '0') : '0';
            $gain = Money::neg($fxNet);
            $result = Money::add($result, $gain);
            $rows[] = ['voucher_id' => $voucher->id, 'number' => $voucher->number, 'date' => $date, 'status' => $voucher->status, 'branch_id' => $voucher->branch_id,
                'from_box' => $boxes[$voucher->cash_box_id] ?? null, 'from_currency' => $from, 'amount' => Money::of((string) $voucher->amount),
                'to_box' => $boxes[$voucher->counter_cash_box_id] ?? null, 'to_currency' => $to, 'counter_amount' => Money::of((string) $voucher->counter_amount),
                'deal_rate' => $rate, 'bulletin_rate' => $bulletin === null ? null : Money::round($bulletin, Money::RATE_SCALE), 'difference' => $difference,
                'difference_pct' => $bulletin === null || ! Money::isPositive($bulletin) ? null : Money::mul(Money::div($difference, $bulletin, 10), '100', 2),
                'fee_amount' => $voucher->fee_amount === null ? null : Money::of((string) $voucher->fee_amount), 'fee_currency' => $codes[$voucher->fee_currency_id] ?? null,
                'result_base' => $gain, 'description' => $voucher->description];
        }
        $positions = $frame->scopeBranches(JournalLine::query()->where('account_id', $fx)->where('entry_date', '<=', $frame->to))->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')->get();
        $baseBalance = '0';
        $byCurrency = [];
        foreach ($positions as $p) {
            $baseBalance = Money::add($baseBalance, Sums::num($p->base_net));
            $byCurrency[] = ['currency_id' => (int) $p->currency_id, 'code' => $codes[$p->currency_id] ?? null, 'balance' => Sums::num($p->net),
                'balance_base' => Sums::num($p->base_net)];
        }
        usort($byCurrency, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        return [...$frame->header(), 'exchanges' => $rows, 'period_result_base' => $result,
            'fx_position' => ['as_of' => $frame->to, 'currencies' => $byCurrency, 'balance_base' => $baseBalance, 'result_base' => Money::neg($baseBalance),
                'result_side' => match (true) {
                    Money::isNegative($baseBalance) => 'ربح تصريف', Money::isPositive($baseBalance) => 'خسارة تصريف', default => 'متوازن'
                }]];
    }

    /** T22: cash counts in the period with their lines (system balance, counted, difference) and the approved differences per box and currency. */
    public function cashCounts(ReportFrame $frame, ?int $boxId = null, ?string $status = null): array
    {
        $counts = $frame->scopeBranches(CashCount::query()->whereBetween('count_date', [$frame->from, $frame->to]), 'branch_id')
            ->when($boxId, fn ($q, $id) => $q->where('cash_box_id', $id))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('count_date')->orderBy('id')->get();
        $lines = CashCountLine::query()->whereIn('cash_count_id', $counts->pluck('id'))
            ->when($frame->currency, fn ($q, $cur) => $q->where('currency_id', $cur->id))->orderBy('id')->get()->groupBy('cash_count_id');
        $codes = Currency::query()->pluck('code', 'id');
        $boxes = CashBox::query()->whereIn('id', $counts->pluck('cash_box_id')->unique())->pluck('name', 'id');
        $rows = [];
        $perBox = [];
        foreach ($counts as $count) {
            $countLines = [];
            foreach ($lines->get($count->id, collect()) as $line) {
                $code = $codes[$line->currency_id] ?? null;
                $difference = $line->difference === null ? null : Money::of((string) $line->difference);
                $countLines[] = ['currency' => $code, 'system_balance' => $line->system_balance === null ? null : Money::of((string) $line->system_balance),
                    'counted_amount' => Money::of((string) $line->counted_amount), 'difference' => $difference, 'explanation' => $line->explanation];
                if ($count->status === 'approved' && $difference !== null) {
                    $key = $count->cash_box_id.'|'.$code;
                    $perBox[$key] ??= ['cash_box_id' => $count->cash_box_id, 'box' => $boxes[$count->cash_box_id] ?? null, 'currency' => $code,
                        'counts' => 0, 'shortage' => '0', 'overage' => '0', 'net_difference' => '0'];
                    $perBox[$key]['counts']++;
                    $perBox[$key][Money::isNegative($difference) ? 'shortage' : 'overage'] = Money::add(
                        $perBox[$key][Money::isNegative($difference) ? 'shortage' : 'overage'], Money::abs($difference));
                    $perBox[$key]['net_difference'] = Money::add($perBox[$key]['net_difference'], $difference);
                }
            }
            $rows[] = ['id' => $count->id, 'cash_box_id' => $count->cash_box_id, 'box' => $boxes[$count->cash_box_id] ?? null, 'branch_id' => $count->branch_id,
                'count_date' => $count->count_date->toDateString(), 'status' => $count->status,
                'counted_by' => $count->counted_by, 'approved_by' => $count->approved_by, 'voucher_id' => $count->voucher_id, 'lines' => $countLines];
        }
        ksort($perBox);

        return [...$frame->header(), 'counts' => $rows, 'per_box' => array_values($perBox)];
    }
}
