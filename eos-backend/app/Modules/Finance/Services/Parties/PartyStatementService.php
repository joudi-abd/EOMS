<?php

namespace App\Modules\Finance\Services\Parties;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Party statement (D18, spec §9.3, report T12): every posted line that carries the party on one of the party-balance
 * accounts — receivables, payables, client and contractor advances, employee advances, custody, salaries payable,
 * partners' current and capital accounts, other receivables and payables, plus any account that requires a party.
 * Per currency (D8): opening balance, each line with its running balance, closing balance. Balances are debit − credit:
 * positive = "لنا" (the party owes us), negative = "علينا" (we owe the party), whatever the account's nature; each
 * account's own side is shown in the per-account totals. Project costs and revenues that carry the party as an
 * analysis dimension are not balances and are left out.
 */
class PartyStatementService
{
    /** System roles whose accounts hold balances with parties. */
    public const PARTY_ROLES = ['receivables_clients', 'client_advances', 'payables_suppliers', 'payables_contractors', 'contractor_advances',
        'employee_advances', 'custody', 'salaries_payable', 'partners_current', 'partners_capital', 'other_receivables', 'other_payables'];

    /** Roles whose accounts carry a party only as an analysis dimension. */
    private const DIMENSION_ONLY_ROLES = ['project_costs', 'project_revenue', 'salaries_expense', 'payroll_allocation_contra',
        'overhead_allocation_contra', 'fx_position', 'transfer_fees', 'opening_balance_equity', 'rounding_differences', 'cash_over_short'];

    public function __construct(private SystemAccounts $roles) {}

    /** Account ids whose party-tagged lines make up a party's balance. @return list<int> */
    public function accountIds(): array
    {
        $ids = array_filter(array_map(fn (string $role) => $this->roles->mappedId($role), self::PARTY_ROLES));
        $excluded = array_filter(array_map(fn (string $role) => $this->roles->mappedId($role), self::DIMENSION_ONLY_ROLES));
        $required = Account::query()->where('requires_party', true)->whereNotIn('id', $excluded)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique([...$ids, ...$required]));
        sort($ids);

        return $ids;
    }

    /**
     * Balances per currency: net (debit − credit) with its side, and the split per account.
     *
     * @param  list<int>|null  $branchIds  null = every branch
     * @return list<array{currency_id: int, code: string, balance: string, side: string, accounts: list<array<string, mixed>>}>
     */
    public function balances(Party $party, ?array $branchIds = null): array
    {
        $rows = $this->lines($party, $branchIds)
            ->groupBy(['fin_journal_lines.currency_id', 'fin_journal_lines.account_id'])
            ->get(['fin_journal_lines.currency_id', 'fin_journal_lines.account_id', DB::raw('SUM(debit) as debit'), DB::raw('SUM(credit) as credit')]);
        $currencies = Currency::query()->whereIn('id', $rows->pluck('currency_id')->unique())->get()->keyBy('id');
        $accounts = Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->get(['id', 'code', 'name', 'type'])->keyBy('id');
        $result = [];
        foreach ($rows->groupBy('currency_id') as $currencyId => $group) {
            $currency = $currencies[$currencyId];
            $perAccount = [];
            foreach ($group->sortBy(fn ($r) => $accounts[$r->account_id]->code) as $row) {
                $balance = Money::round(Money::sub(Money::of((string) $row->debit), Money::of((string) $row->credit)), $currency->decimals);
                if (Money::isZero($balance)) {
                    continue;
                }
                $account = $accounts[$row->account_id];
                $perAccount[] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
                    'nature' => $this->nature($account->type), 'balance' => $balance, 'side' => self::side($balance)];
            }
            $net = Money::sum(array_column($perAccount, 'balance'));
            $result[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'balance' => $net, 'side' => self::side($net), 'accounts' => $perAccount];
        }
        usort($result, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $result;
    }

    /**
     * The statement (T12). Filters: `currency_id` (one currency, else every currency the party has lines in),
     * `project_id`, `from`, `to` (Y-m-d). Without `from` the opening balance is zero and every line up to `to` is listed.
     *
     * @param  array{currency_id?: int|null, project_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @param  list<int>|null  $branchIds  null = every branch (R6 for branch accountants)
     * @return array{party: array, filters: array, currencies: list<array<string, mixed>>}
     */
    public function statement(Party $party, array $filters = [], ?array $branchIds = null): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $projectId = $filters['project_id'] ?? null;
        $currencyId = $filters['currency_id'] ?? null;
        $base = fn () => $this->lines($party, $branchIds)
            ->when($projectId, fn (Builder $q, $id) => $q->where('fin_journal_lines.project_id', $id))
            ->when($currencyId, fn (Builder $q, $id) => $q->where('fin_journal_lines.currency_id', $id));
        $currencyIds = $base()->when($to, fn (Builder $q, $d) => $q->where('fin_journal_lines.entry_date', '<=', $d))
            ->distinct()->pluck('fin_journal_lines.currency_id')->map(fn ($id) => (int) $id)->all();
        if ($currencyId !== null && ! in_array((int) $currencyId, $currencyIds, true)) {
            $currencyIds[] = (int) $currencyId;
        }
        $currencies = Currency::query()->whereIn('id', $currencyIds)->orderBy('code')->get();
        $accounts = Account::query()->whereIn('id', $this->accountIds())->get(['id', 'code', 'name', 'type'])->keyBy('id');

        $result = [];
        foreach ($currencies as $currency) {
            $opening = $from === null ? '0' : Money::round(Money::of((string) $base()->where('fin_journal_lines.currency_id', $currency->id)
                ->where('fin_journal_lines.entry_date', '<', $from)->sum(DB::raw('fin_journal_lines.debit - fin_journal_lines.credit'))), $currency->decimals);
            $lines = $base()->where('fin_journal_lines.currency_id', $currency->id)
                ->join('fin_journal_entries as e', 'e.id', '=', 'fin_journal_lines.journal_entry_id')
                ->when($from, fn (Builder $q, $d) => $q->where('fin_journal_lines.entry_date', '>=', $d))
                ->when($to, fn (Builder $q, $d) => $q->where('fin_journal_lines.entry_date', '<=', $d))
                ->orderBy('fin_journal_lines.entry_date')->orderBy('fin_journal_lines.journal_entry_id')->orderBy('fin_journal_lines.line_no')
                ->get(['fin_journal_lines.*', 'e.number as entry_number', 'e.description as entry_description', 'e.source_type', 'e.source_id']);
            $vouchers = Voucher::query()->whereIn('id', $lines->where('source_type', 'voucher')->pluck('source_id')->unique())
                ->get(['id', 'number', 'type', 'purpose'])->keyBy('id');
            $running = $opening;
            $rows = [];
            foreach ($lines as $line) {
                $running = Money::add($running, Money::sub((string) $line->debit, (string) $line->credit));
                $voucher = $line->source_type === 'voucher' ? $vouchers->get($line->source_id) : null;
                $account = $accounts[$line->account_id] ?? null;
                $rows[] = ['date' => $line->entry_date->toDateString(), 'entry_id' => $line->journal_entry_id, 'entry_number' => $line->entry_number,
                    'voucher_id' => $voucher?->id, 'voucher_number' => $voucher?->number, 'voucher_type' => $voucher?->type, 'voucher_purpose' => $voucher?->purpose,
                    'account_id' => $line->account_id, 'account_code' => $account?->code, 'account_name' => $account?->name,
                    'project_id' => $line->project_id, 'description' => $line->entry_description, 'memo' => $line->memo,
                    'debit' => Money::of((string) $line->debit), 'credit' => Money::of((string) $line->credit), 'balance' => $running, 'side' => self::side($running)];
            }
            $result[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'opening' => $opening, 'opening_side' => self::side($opening),
                'lines' => $rows, 'total_debit' => Money::sum(array_column($rows, 'debit')), 'total_credit' => Money::sum(array_column($rows, 'credit')),
                'closing' => $running, 'closing_side' => self::side($running)];
        }

        return ['party' => $party->only(['id', 'name', 'phone', 'is_active']), 'filters' => ['currency_id' => $currencyId, 'project_id' => $projectId,
            'from' => $from, 'to' => $to], 'currencies' => $result];
    }

    /** "لنا" for a debit balance (the party owes us), "علينا" for a credit balance, "متوازن" at zero. */
    public static function side(string $balance): string
    {
        return match (true) {
            Money::isPositive($balance) => 'لنا',
            Money::isNegative($balance) => 'علينا',
            default => 'متوازن',
        };
    }

    /** The side an account normally sits on: assets debit (لنا), liabilities and equity credit (علينا). */
    private function nature(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'لنا' : 'علينا';
    }

    /** Posted lines (reversed entries and their reversals both stay, so they net out) of the party on party accounts. */
    private function lines(Party $party, ?array $branchIds): Builder
    {
        return JournalLine::query()->where('fin_journal_lines.party_id', $party->id)->whereIn('fin_journal_lines.account_id', $this->accountIds())
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('fin_journal_lines.branch_id', $branchIds));
    }
}
