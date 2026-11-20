<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Parties\PartyStatementService;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Carbon\CarbonImmutable;

/**
 * Party reports: T12 party statement (PartyStatementService within the report reach), T14 receivables and payables
 * aging, T17 partners' current accounts per month.
 */
class PartyReports
{
    /** Debit-nature party accounts aged as receivables (لنا). */
    public const RECEIVABLE_ROLES = ['receivables_clients', 'other_receivables'];

    /** Credit-nature party accounts aged as payables (علينا). */
    public const PAYABLE_ROLES = ['payables_suppliers', 'payables_contractors', 'other_payables'];

    public const BUCKETS = ['0_30' => [0, 30], '31_60' => [31, 60], '61_90' => [61, 90], '90_plus' => [91, null]];

    public function __construct(private PartyStatementService $statements, private SystemAccounts $roles) {}

    /** T12, with the opening and closing of each currency in the base currency when consolidated. */
    public function statement(ReportFrame $frame, Party $party, ?int $projectId, bool $fromStart): array
    {
        $statement = $this->statements->statement($party, ['currency_id' => $frame->currencyId(), 'project_id' => $projectId,
            'from' => $fromStart ? null : $frame->from, 'to' => $frame->to], $frame->branchIds);
        if ($frame->consolidated) {
            $total = ['opening' => '0', 'closing' => '0'];
            foreach ($statement['currencies'] as $i => $block) {
                $opening = $frame->consolidator->atReportDate($block['opening'], $block['currency_id']);
                $closing = $frame->consolidator->atReportDate($block['closing'], $block['currency_id']);
                $statement['currencies'][$i]['base'] = ['opening' => $opening, 'closing' => $closing];
                $total = ['opening' => Money::add($total['opening'], $opening), 'closing' => Money::add($total['closing'], $closing)];
            }
            $statement['consolidated'] = [...$total, 'closing_side' => PartyStatementService::side($total['closing'])];
        }

        return [...$frame->header(), ...$statement];
    }

    /**
     * T14 on `to`. Method (FIFO): per party, currency and side, the lines are taken day by day; on the receivable side
     * each day's debits open an item dated that day and credits settle the oldest open items first (the payable side
     * mirrors it). What stays open is aged by the days from its date to `to` into 0–30, 31–60, 61–90 and 90+. Credits
     * beyond every open item (an advance or an overpayment) are shown as `unapplied` and not aged.
     *
     * @param  'receivable'|'payable'|null  $side
     */
    public function aging(ReportFrame $frame, ?string $side = null, ?int $partyId = null): array
    {
        $sides = ['receivable' => self::RECEIVABLE_ROLES, 'payable' => self::PAYABLE_ROLES];
        if ($side !== null) {
            $sides = [$side => $sides[$side]];
        }
        $codes = Currency::query()->pluck('code', 'id');
        $asOf = CarbonImmutable::parse($frame->to);
        $rows = [];
        $totals = [];
        foreach ($sides as $name => $roles) {
            $accountIds = array_values(array_filter(array_map(fn ($r) => $this->roles->mappedId($r), $roles)));
            $days = $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()->whereIn('account_id', $accountIds)
                ->whereNotNull('party_id')->where('entry_date', '<=', $frame->to)))
                ->when($partyId, fn ($q, $id) => $q->where('party_id', $id))
                ->groupBy('party_id', 'currency_id', 'entry_date')->orderBy('entry_date')
                ->selectRaw('party_id, currency_id, entry_date, SUM(debit) as debit, SUM(credit) as credit')->get();
            foreach ($days->groupBy(fn ($d) => $d->party_id.'|'.$d->currency_id) as $key => $group) {
                [$party, $currency] = array_map('intval', explode('|', $key));
                $open = [];
                $unapplied = '0';
                foreach ($group->sortBy('entry_date') as $day) {
                    [$adds, $settles] = $name === 'receivable' ? [Sums::num($day->debit), Sums::num($day->credit)] : [Sums::num($day->credit), Sums::num($day->debit)];
                    $date = substr((string) $day->entry_date, 0, 10);
                    // Earlier unapplied money settles the new item first.
                    if (Money::isPositive($adds) && Money::isPositive($unapplied)) {
                        $used = Money::min($unapplied, $adds);
                        $adds = Money::sub($adds, $used);
                        $unapplied = Money::sub($unapplied, $used);
                    }
                    if (Money::isPositive($adds)) {
                        $open[] = ['date' => $date, 'amount' => $adds];
                    }
                    while (Money::isPositive($settles) && $open !== []) {
                        $used = Money::min($settles, $open[0]['amount']);
                        $open[0]['amount'] = Money::sub($open[0]['amount'], $used);
                        $settles = Money::sub($settles, $used);
                        if (! Money::isPositive($open[0]['amount'])) {
                            array_shift($open);
                        }
                    }
                    $unapplied = Money::add($unapplied, $settles);
                }
                $buckets = array_fill_keys(array_keys(self::BUCKETS), '0');
                $oldest = null;
                foreach ($open as $item) {
                    $age = (int) CarbonImmutable::parse($item['date'])->diffInDays($asOf);
                    $oldest ??= $item['date'];
                    foreach (self::BUCKETS as $bucket => [$min, $max]) {
                        if ($age >= $min && ($max === null || $age <= $max)) {
                            $buckets[$bucket] = Money::add($buckets[$bucket], $item['amount']);
                        }
                    }
                }
                $outstanding = Money::sum($buckets);
                if (Money::isZero($outstanding) && Money::isZero($unapplied)) {
                    continue;
                }
                $code = $codes[$currency] ?? null;
                $rows[] = ['side' => $name, 'party_id' => $party, 'currency_id' => $currency, 'currency' => $code, 'outstanding' => $outstanding,
                    ...$buckets, 'unapplied' => $unapplied, 'net' => Money::sub($outstanding, $unapplied), 'oldest_open_date' => $oldest];
                $totals["$name|$code"] ??= ['side' => $name, 'currency' => $code, 'outstanding' => '0', ...array_fill_keys(array_keys(self::BUCKETS), '0'),
                    'unapplied' => '0'];
                foreach (['outstanding', 'unapplied', ...array_keys(self::BUCKETS)] as $k) {
                    $totals["$name|$code"][$k] = Money::add($totals["$name|$code"][$k], $k === 'outstanding' ? $outstanding : ($k === 'unapplied' ? $unapplied : $buckets[$k]));
                }
            }
        }
        $names = Party::query()->whereIn('id', array_column($rows, 'party_id'))->pluck('name', 'id');
        foreach ($rows as $i => $row) {
            $rows[$i]['party'] = $names[$row['party_id']] ?? null;
        }
        usort($rows, fn ($a, $b) => [$a['side'], $a['party'], $a['currency']] <=> [$b['side'], $b['party'], $b['currency']]);
        ksort($totals);

        return [...$frame->header(), 'as_of' => $frame->to, 'method' => 'fifo', 'buckets' => array_keys(self::BUCKETS), 'rows' => $rows,
            'totals' => array_values($totals)];
    }

    /**
     * T17: per partner and currency, the balance before `from`, then per month the drawings (debits on
     * partners_current), the deposits (credits) and the running balance (debit − credit: positive = the partner owes
     * the company).
     */
    public function partners(ReportFrame $frame, ?int $partyId = null): array
    {
        $account = $this->roles->mappedId('partners_current') ?? 0;
        $base = fn () => $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()->where('account_id', $account)->whereNotNull('party_id')))
            ->when($partyId, fn ($q, $id) => $q->where('party_id', $id));
        $openings = $base()->where('entry_date', '<', $frame->from)->groupBy('party_id', 'currency_id')
            ->selectRaw('party_id, currency_id, SUM(debit) - SUM(credit) as net')->get()->keyBy(fn ($r) => $r->party_id.'|'.$r->currency_id);
        $movements = $base()->whereBetween('entry_date', [$frame->from, $frame->to])->get(['party_id', 'currency_id', 'entry_date', 'debit', 'credit']);
        $byMonth = [];
        foreach ($movements as $line) {
            $key = $line->party_id.'|'.$line->currency_id;
            $month = $line->entry_date->format('Y-m');
            $byMonth[$key][$month]['drawings'] = Money::add($byMonth[$key][$month]['drawings'] ?? '0', (string) $line->debit);
            $byMonth[$key][$month]['deposits'] = Money::add($byMonth[$key][$month]['deposits'] ?? '0', (string) $line->credit);
        }
        $months = self::months($frame->from, $frame->to);
        $codes = Currency::query()->pluck('code', 'id');
        $keys = $openings->keys()->merge(array_keys($byMonth))->unique();
        $names = Party::query()->whereIn('id', $keys->map(fn ($k) => (int) explode('|', $k)[0]))->pluck('name', 'id');
        $rows = [];
        foreach ($keys as $key) {
            [$party, $currency] = array_map('intval', explode('|', $key));
            $running = Sums::num($openings->get($key)?->net);
            $opening = $running;
            $cells = [];
            foreach ($months as $month) {
                $drawings = $byMonth[$key][$month]['drawings'] ?? '0';
                $deposits = $byMonth[$key][$month]['deposits'] ?? '0';
                $running = Money::add($running, Money::sub($drawings, $deposits));
                $cells[] = ['month' => $month, 'drawings' => $drawings, 'deposits' => $deposits, 'balance' => $running];
            }
            $rows[] = ['party_id' => $party, 'partner' => $names[$party] ?? null, 'currency_id' => $currency, 'currency' => $codes[$currency] ?? null,
                'opening' => $opening, 'months' => $cells, 'total_drawings' => Money::sum(array_column($cells, 'drawings')),
                'total_deposits' => Money::sum(array_column($cells, 'deposits')), 'closing' => $running, 'closing_side' => PartyStatementService::side($running)];
        }
        usort($rows, fn ($a, $b) => [$a['partner'], $a['currency']] <=> [$b['partner'], $b['currency']]);

        return [...$frame->header(), 'months' => $months, 'partners' => $rows];
    }

    /** @return list<string> every `Y-m` from `$from` to `$to` */
    public static function months(string $from, string $to): array
    {
        $months = [];
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }
}
