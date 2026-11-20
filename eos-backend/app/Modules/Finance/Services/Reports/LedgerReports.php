<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ledger reports straight from the journal lines (the source of truth): T3 journal, T4 general ledger, T5 trial
 * balance. Reversed entries and their reversals both stay, so they net out in every sum. Branch reach applies to the
 * lines' branch (the journal: the entry's branch).
 */
class LedgerReports
{
    /**
     * T3: entries of the period with their lines, newest last. Filters: `account_id`, `voucher_type`, `party_id`,
     * `project_id`, `search` (entry number or description) and the frame's currency (entries having a line in it).
     * Totals per currency cover every matching entry, not just the page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{report: array, page: LengthAwarePaginator}
     */
    public function journal(ReportFrame $frame, array $filters, int $perPage = 25): array
    {
        $query = $this->journalQuery($frame, $filters);
        $page = (clone $query)->orderBy('entry_date')->orderBy('id')->paginate(min(100, max(1, $perPage)));
        $entries = collect($page->items());
        $lines = JournalLine::query()->whereIn('journal_entry_id', $entries->pluck('id'))->orderBy('journal_entry_id')->orderBy('line_no')->get()
            ->groupBy('journal_entry_id');
        $lookups = $this->lookups($lines->flatten(1), $entries);
        $rows = $entries->map(fn (JournalEntry $e) => $this->presentEntry($e, $lines->get($e->id, collect()), $lookups))->all();
        $totals = JournalLine::query()->whereIn('journal_entry_id', (clone $query)->select('id'))->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(debit) as debit, SUM(credit) as credit')->get()
            ->map(fn ($t) => ['currency_id' => (int) $t->currency_id, 'code' => $lookups['currencies'][$t->currency_id]->code ?? null,
                'debit' => Sums::num($t->debit), 'credit' => Sums::num($t->credit)])
            ->sortBy('code')->values()->all();

        return ['report' => [...$frame->header(), 'entries' => $rows, 'totals' => $totals], 'page' => $page];
    }

    /**
     * T3 as flat rows (one per line) for the CSV export: every matching entry, streamed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function journalRows(ReportFrame $frame, array $filters): Generator
    {
        foreach ($this->journalQuery($frame, $filters)->orderBy('entry_date')->orderBy('id')->lazy(200)->chunk(200) as $lazy) {
            $chunk = collect($lazy->all());
            $lines = JournalLine::query()->whereIn('journal_entry_id', $chunk->pluck('id'))->orderBy('journal_entry_id')->orderBy('line_no')->get()
                ->groupBy('journal_entry_id');
            $lookups = $this->lookups($lines->flatten(1), $chunk);
            foreach ($chunk as $entry) {
                $presented = $this->presentEntry($entry, $lines->get($entry->id, collect()), $lookups);
                foreach ($presented['lines'] as $line) {
                    yield [$presented['date'], $presented['number'], $presented['voucher_number'], $presented['voucher_type'], $presented['description'],
                        $line['account_code'], $line['account_name'], $line['currency'], $line['debit'], $line['credit'], $line['party'], $line['project_id'], $line['memo']];
                }
            }
        }
    }

    /**
     * T4: one or several accounts (a group account brings its postable descendants). Per account and currency: the
     * opening balance before `from`, each line of the period with its running balance, the totals and the closing
     * balance; consolidated adds the base-currency figures per account.
     *
     * @param  list<int>  $accountIds
     */
    public function generalLedger(ReportFrame $frame, array $accountIds): array
    {
        $accounts = $this->expand($accountIds);
        $ids = $accounts->keys()->all();
        $base = fn () => $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()->whereIn('fin_journal_lines.account_id', $ids)));
        $openings = $base()->where('fin_journal_lines.entry_date', '<', $frame->from)->groupBy('account_id', 'currency_id')
            ->selectRaw('account_id, currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')->get()
            ->keyBy(fn ($r) => $r->account_id.'|'.$r->currency_id);
        $lines = $base()->whereBetween('fin_journal_lines.entry_date', [$frame->from, $frame->to])
            ->join('fin_journal_entries as e', 'e.id', '=', 'fin_journal_lines.journal_entry_id')
            ->orderBy('fin_journal_lines.entry_date')->orderBy('fin_journal_lines.journal_entry_id')->orderBy('fin_journal_lines.line_no')
            ->get(['fin_journal_lines.*', 'e.number as entry_number', 'e.description as entry_description', 'e.source_type', 'e.source_id']);
        $vouchers = Voucher::query()->whereIn('id', $lines->where('source_type', 'voucher')->pluck('source_id')->unique())->get(['id', 'number', 'type'])->keyBy('id');
        $parties = Party::query()->whereIn('id', $lines->pluck('party_id')->filter()->unique())->pluck('name', 'id');
        $currencies = Currency::query()->get()->keyBy('id');
        $byKey = $lines->groupBy(fn ($l) => $l->account_id.'|'.$l->currency_id);
        $keys = $openings->keys()->merge($byKey->keys())->unique();
        $c = $frame->consolidator;
        $result = [];
        foreach ($accounts as $account) {
            $blocks = [];
            $accountBase = ['opening' => '0', 'debit' => '0', 'credit' => '0', 'closing' => '0'];
            foreach ($keys->filter(fn ($k) => str_starts_with($k, $account->id.'|')) as $key) {
                $currency = $currencies[(int) explode('|', $key)[1]];
                $opening = Sums::num($openings->get($key)?->net);
                $running = $opening;
                $rows = [];
                $baseDebit = '0';
                $baseCredit = '0';
                foreach ($byKey->get($key, collect()) as $line) {
                    $running = Money::add($running, Money::sub((string) $line->debit, (string) $line->credit));
                    $voucher = $line->source_type === 'voucher' ? $vouchers->get($line->source_id) : null;
                    $baseDebit = Money::add($baseDebit, (string) $line->base_debit);
                    $baseCredit = Money::add($baseCredit, (string) $line->base_credit);
                    $rows[] = ['date' => $line->entry_date->toDateString(), 'entry_id' => $line->journal_entry_id, 'entry_number' => $line->entry_number,
                        'voucher_id' => $voucher?->id, 'voucher_number' => $voucher?->number, 'voucher_type' => $voucher?->type,
                        'description' => $line->entry_description, 'memo' => $line->memo, 'party_id' => $line->party_id, 'party' => $parties[$line->party_id] ?? null,
                        'project_id' => $line->project_id, 'debit' => Money::of((string) $line->debit), 'credit' => Money::of((string) $line->credit),
                        'balance' => $running];
                }
                $debit = Money::sum(array_column($rows, 'debit'));
                $credit = Money::sum(array_column($rows, 'credit'));
                $block = ['currency_id' => $currency->id, 'code' => $currency->code, 'opening' => $opening, 'lines' => $rows, 'total_debit' => $debit,
                    'total_credit' => $credit, 'closing' => $running];
                if ($frame->consolidated) {
                    $figures = ['opening' => $c->value($opening, Sums::num($openings->get($key)?->base_net), $currency->id),
                        'debit' => $c->value($debit, $baseDebit, $currency->id), 'credit' => $c->value($credit, $baseCredit, $currency->id)];
                    $figures['closing'] = Money::sub(Money::add($figures['opening'], $figures['debit']), $figures['credit']);
                    $block['base'] = $figures;
                    foreach ($accountBase as $k => $v) {
                        $accountBase[$k] = Money::add($v, $figures[$k]);
                    }
                }
                $blocks[] = $block;
            }
            usort($blocks, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $row = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'currencies' => $blocks];
            if ($frame->consolidated) {
                $row['consolidated'] = $accountBase;
            }
            $result[] = $row;
        }

        return [...$frame->header(), 'accounts' => $result];
    }

    /**
     * T5: per currency, every account with a balance or a movement: opening before `from`, period debits and credits,
     * closing (split into debit and credit columns); the totals of each column balance. Consolidated: the same per
     * account in the base currency, all currencies together.
     */
    public function trialBalance(ReportFrame $frame): array
    {
        $rows = $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()->where('entry_date', '<=', $frame->to)))
            ->groupBy('account_id', 'currency_id')
            ->selectRaw('account_id, currency_id,
                SUM(CASE WHEN entry_date < ? THEN debit - credit ELSE 0 END) as opening,
                SUM(CASE WHEN entry_date >= ? THEN debit ELSE 0 END) as debit,
                SUM(CASE WHEN entry_date >= ? THEN credit ELSE 0 END) as credit,
                SUM(CASE WHEN entry_date < ? THEN base_debit - base_credit ELSE 0 END) as base_opening,
                SUM(CASE WHEN entry_date >= ? THEN base_debit ELSE 0 END) as base_debit,
                SUM(CASE WHEN entry_date >= ? THEN base_credit ELSE 0 END) as base_credit',
                array_fill(0, 6, $frame->from))
            ->get();
        $accounts = Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->get(['id', 'code', 'name', 'type', 'parent_id'])->keyBy('id');
        $currencies = Currency::query()->get()->keyBy('id');
        $c = $frame->consolidator;
        $blocks = [];
        $consolidated = [];
        foreach ($rows->groupBy('currency_id') as $currencyId => $group) {
            $currency = $currencies[$currencyId];
            $lines = [];
            foreach ($group as $r) {
                $account = $accounts[$r->account_id];
                $figures = ['opening' => Sums::num($r->opening), 'debit' => Sums::num($r->debit), 'credit' => Sums::num($r->credit)];
                $figures['closing'] = Money::sub(Money::add($figures['opening'], $figures['debit']), $figures['credit']);
                if (Money::isZero($figures['opening']) && Money::isZero($figures['debit']) && Money::isZero($figures['credit'])) {
                    continue;
                }
                $lines[] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, ...self::split($figures)];
                if ($frame->consolidated) {
                    $converted = ['opening' => $c->value($figures['opening'], Sums::num($r->base_opening), $currency->id),
                        'debit' => $c->value($figures['debit'], Sums::num($r->base_debit), $currency->id),
                        'credit' => $c->value($figures['credit'], Sums::num($r->base_credit), $currency->id)];
                    $entry = $consolidated[$account->id] ?? ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name,
                        'type' => $account->type, 'opening' => '0', 'debit' => '0', 'credit' => '0'];
                    foreach (['opening', 'debit', 'credit'] as $k) {
                        $entry[$k] = Money::add($entry[$k], $converted[$k]);
                    }
                    $consolidated[$account->id] = $entry;
                }
            }
            usort($lines, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $blocks[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'accounts' => $lines, 'totals' => self::totals($lines)];
        }
        usort($blocks, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $result = ['currencies' => $blocks];
        if ($frame->consolidated) {
            $lines = [];
            foreach ($consolidated as $entry) {
                $entry['closing'] = Money::sub(Money::add($entry['opening'], $entry['debit']), $entry['credit']);
                $lines[] = [...array_diff_key($entry, array_flip(['opening', 'debit', 'credit', 'closing'])), ...self::split($entry)];
            }
            usort($lines, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $result['consolidated'] = ['accounts' => $lines, 'totals' => self::totals($lines)];
        }

        return [...$frame->header(), ...$result];
    }

    /**
     * Opening and closing as signed balances plus debit/credit columns.
     *
     * @param  array{opening: string, debit: string, credit: string, closing: string}  $f
     */
    private static function split(array $f): array
    {
        return [
            'opening' => $f['opening'], 'opening_debit' => Money::max('0', $f['opening']), 'opening_credit' => Money::max('0', Money::neg($f['opening'])),
            'debit' => $f['debit'], 'credit' => $f['credit'],
            'closing' => $f['closing'], 'closing_debit' => Money::max('0', $f['closing']), 'closing_credit' => Money::max('0', Money::neg($f['closing'])),
        ];
    }

    /** @param  list<array<string, string>>  $lines */
    private static function totals(array $lines): array
    {
        $totals = [];
        foreach (['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'] as $column) {
            $totals[$column] = Money::sum(array_column($lines, $column));
        }
        $totals['balanced'] = Money::cmp($totals['debit'], $totals['credit']) === 0 && Money::cmp($totals['closing_debit'], $totals['closing_credit']) === 0
            && Money::cmp($totals['opening_debit'], $totals['opening_credit']) === 0;

        return $totals;
    }

    /** @param  array<string, mixed>  $filters */
    private function journalQuery(ReportFrame $frame, array $filters): Builder
    {
        $withLines = fn (callable $where) => fn (Builder $q) => $q->whereIn('id', JournalLine::query()->select('journal_entry_id')->where($where));

        return $frame->scopeBranches(JournalEntry::query()->whereBetween('entry_date', [$frame->from, $frame->to]), 'branch_id')
            ->when($frame->currency, fn ($q, $cur) => $withLines(fn ($l) => $l->where('currency_id', $cur->id))($q))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $withLines(fn ($l) => $l->where('account_id', $id))($q))
            ->when($filters['party_id'] ?? null, fn ($q, $id) => $withLines(fn ($l) => $l->where('party_id', $id))($q))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $withLines(fn ($l) => $l->where('project_id', $id))($q))
            ->when($filters['voucher_type'] ?? null, fn ($q, $type) => $q->where('source_type', 'voucher')
                ->whereIn('source_id', Voucher::query()->select('id')->where('type', $type)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%$s%")->orWhere('description', 'like', "%$s%")));
    }

    /**
     * @param  Collection<int, JournalLine>  $lines
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function lookups(Collection $lines, Collection $entries): array
    {
        return [
            'accounts' => Account::query()->whereIn('id', $lines->pluck('account_id')->unique())->get(['id', 'code', 'name'])->keyBy('id'),
            'currencies' => Currency::query()->get()->keyBy('id'),
            'parties' => Party::query()->whereIn('id', $lines->pluck('party_id')->filter()->unique())->pluck('name', 'id'),
            'vouchers' => Voucher::query()->whereIn('id', $entries->where('source_type', 'voucher')->pluck('source_id')->unique())
                ->get(['id', 'number', 'type', 'purpose'])->keyBy('id'),
        ];
    }

    private function presentEntry(JournalEntry $entry, Collection $lines, array $lookups): array
    {
        $voucher = $entry->source_type === 'voucher' ? $lookups['vouchers']->get($entry->source_id) : null;

        return [
            'id' => $entry->id, 'number' => $entry->number, 'date' => $entry->entry_date->toDateString(), 'branch_id' => $entry->branch_id,
            'status' => $entry->status, 'reversal_of_id' => $entry->reversal_of_id, 'description' => $entry->description,
            'source_type' => $entry->source_type, 'source_id' => $entry->source_id, 'voucher_number' => $voucher?->number, 'voucher_type' => $voucher?->type,
            'voucher_purpose' => $voucher?->purpose,
            'lines' => $lines->map(fn (JournalLine $l) => [
                'line_no' => $l->line_no, 'account_id' => $l->account_id, 'account_code' => $lookups['accounts'][$l->account_id]->code ?? null,
                'account_name' => $lookups['accounts'][$l->account_id]->name ?? null, 'currency_id' => $l->currency_id,
                'currency' => $lookups['currencies'][$l->currency_id]->code ?? null, 'debit' => Money::of((string) $l->debit), 'credit' => Money::of((string) $l->credit),
                'rate_to_base' => Money::of((string) $l->rate_to_base), 'base_debit' => Money::of((string) $l->base_debit), 'base_credit' => Money::of((string) $l->base_credit),
                'deal_rate' => $l->deal_rate === null ? null : Money::of((string) $l->deal_rate), 'branch_id' => $l->branch_id,
                'party_id' => $l->party_id, 'party' => $lookups['parties'][$l->party_id] ?? null, 'project_id' => $l->project_id,
                'project_cost_category_id' => $l->project_cost_category_id, 'expense_category_id' => $l->expense_category_id, 'asset_id' => $l->asset_id,
                'employee_id' => $l->employee_id, 'contractor_agreement_id' => $l->contractor_agreement_id, 'custody_id' => $l->custody_id, 'memo' => $l->memo,
            ])->values()->all(),
        ];
    }

    /**
     * The accounts asked for; a group account brings every descendant (only accounts with lines show figures).
     *
     * @param  list<int>  $accountIds
     * @return Collection<int, Account> keyed by id, ordered by code
     */
    private function expand(array $accountIds): Collection
    {
        $all = Account::query()->get(['id', 'code', 'name', 'type', 'parent_id', 'is_postable'])->keyBy('id');
        $children = $all->groupBy('parent_id');
        $picked = [];
        $queue = array_values(array_unique(array_map('intval', $accountIds)));
        foreach ($queue as $id) {
            if (! $all->has($id)) {
                throw ValidationException::withMessages(['account_ids' => ['الحساب غير موجود.']]);
            }
        }
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($picked[$id])) {
                continue;
            }
            $picked[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $all->filter(fn (Account $a) => isset($picked[$a->id]) && ($a->is_postable || ! $children->has($a->id)))->sortBy('code');
    }
}
