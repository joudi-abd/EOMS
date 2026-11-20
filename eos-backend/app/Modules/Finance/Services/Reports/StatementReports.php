<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Collection;

/**
 * Financial statements from the journal lines, per currency (D8) and consolidated:
 * T6 income statement for the period (company, and per branch with `by_branch`, D12),
 * T7 balance sheet on a day: assets = liabilities + equity + profit to date. There are no closing entries, so the
 * profit to date is every revenue less every expense posted up to that day.
 */
class StatementReports
{
    /** Accounts as the statements sign them: assets and expenses debit − credit, the others credit − debit. */
    public const DEBIT_NATURE = ['asset', 'expense'];

    /** T6. With `$byBranch` every branch of the reach also gets its own statement. */
    public function incomeStatement(ReportFrame $frame, bool $byBranch = false): array
    {
        $rows = $this->sums($frame, ['revenue', 'expense'], $frame->from, $byBranch);
        $accounts = $this->accounts($rows);
        $result = $this->income($frame, $rows, $accounts);
        if ($byBranch) {
            $branches = Branch::query()->whereIn('id', $rows->pluck('branch_id')->unique())->orderBy('id')->get(['id', 'name', 'code']);
            $result['branches'] = $branches->map(fn (Branch $b) => ['branch_id' => $b->id, 'name' => $b->name, 'code' => $b->code,
                ...$this->income($frame, $rows->where('branch_id', $b->id), $accounts)])->values()->all();
        }

        return [...$frame->header(), 'from' => $frame->from, 'to' => $frame->to, ...$result];
    }

    /** T7 on `to` (`as_of`). */
    public function balanceSheet(ReportFrame $frame): array
    {
        $rows = $this->sums($frame, null, null, false);
        $accounts = $this->accounts($rows);
        $currencies = Currency::query()->get()->keyBy('id');
        $c = $frame->consolidator;
        $blocks = [];
        $consolidated = ['asset' => [], 'liability' => [], 'equity' => [], 'revenue' => '0', 'expense' => '0'];
        foreach ($rows->groupBy('currency_id') as $currencyId => $group) {
            $currency = $currencies[$currencyId];
            $sections = ['asset' => [], 'liability' => [], 'equity' => []];
            $revenue = '0';
            $expense = '0';
            foreach ($group as $r) {
                $account = $accounts[$r->account_id];
                $amount = $this->signed($account->type, Sums::num($r->net));
                $base = $frame->consolidated ? $c->value($amount, $this->signed($account->type, Sums::num($r->base_net)), $currency->id) : null;
                if ($account->type === 'revenue' || $account->type === 'expense') {
                    if ($account->type === 'revenue') {
                        $revenue = Money::add($revenue, $amount);
                    } else {
                        $expense = Money::add($expense, $amount);
                    }
                    if ($base !== null) {
                        $consolidated[$account->type] = Money::add($consolidated[$account->type], $base);
                    }

                    continue;
                }
                if (Money::isZero($amount)) {
                    continue;
                }
                $sections[$account->type][] = $this->row($account, $amount);
                if ($base !== null) {
                    $consolidated[$account->type][$account->id] = $this->row($account, Money::add($consolidated[$account->type][$account->id]['amount'] ?? '0', $base));
                }
            }
            $blocks[] = ['currency_id' => $currency->id, 'code' => $currency->code, ...$this->sheet($sections, Money::sub($revenue, $expense))];
        }
        usort($blocks, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $result = ['as_of' => $frame->to, 'currencies' => $blocks];
        if ($frame->consolidated) {
            $result['consolidated'] = $this->sheet(['asset' => array_values($consolidated['asset']), 'liability' => array_values($consolidated['liability']),
                'equity' => array_values($consolidated['equity'])], Money::sub($consolidated['revenue'], $consolidated['expense']));
        }

        return [...$frame->header(), ...$result];
    }

    /**
     * Sums per (branch?, currency, account) of the frame's lines: movements from `$from` to `to` (null = everything up
     * to `to`), optionally only of some account types.
     *
     * @param  list<string>|null  $types
     */
    private function sums(ReportFrame $frame, ?array $types, ?string $from, bool $byBranch): Collection
    {
        $group = $byBranch ? ['fin_journal_lines.branch_id', 'fin_journal_lines.currency_id', 'fin_journal_lines.account_id']
            : ['fin_journal_lines.currency_id', 'fin_journal_lines.account_id'];

        return $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()))
            ->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')
            ->when($types !== null, fn ($q) => $q->whereIn('a.type', $types))
            ->when($from !== null, fn ($q) => $q->where('fin_journal_lines.entry_date', '>=', $from))
            ->where('fin_journal_lines.entry_date', '<=', $frame->to)
            ->groupBy($group)
            ->selectRaw(implode(', ', $group).', SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as net,
                SUM(fin_journal_lines.base_debit) - SUM(fin_journal_lines.base_credit) as base_net')
            ->get();
    }

    /** @return Collection<int, Account> */
    private function accounts(Collection $rows): Collection
    {
        return Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->get(['id', 'code', 'name', 'type', 'parent_id'])->keyBy('id');
    }

    /** debit − credit → the statement's sign for the account type. */
    private function signed(string $type, string $net): string
    {
        return in_array($type, self::DEBIT_NATURE, true) ? $net : Money::neg($net);
    }

    private function row(Account $account, string $amount): array
    {
        return ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => $amount];
    }

    /** Income statement blocks (per currency, consolidated) from per-(currency, account) sums. */
    private function income(ReportFrame $frame, Collection $rows, Collection $accounts): array
    {
        $currencies = Currency::query()->get()->keyBy('id');
        $c = $frame->consolidator;
        $blocks = [];
        $consolidated = ['revenue' => [], 'expense' => []];
        foreach ($rows->groupBy('currency_id') as $currencyId => $group) {
            $currency = $currencies[$currencyId];
            $sections = ['revenue' => [], 'expense' => []];
            foreach ($group->groupBy('account_id') as $accountId => $sums) {
                $account = $accounts[$accountId];
                $amount = $this->signed($account->type, Money::sum($sums->map(fn ($s) => Sums::num($s->net))));
                if (! Money::isZero($amount)) {
                    $sections[$account->type][] = $this->row($account, $amount);
                }
                if ($frame->consolidated) {
                    $base = $c->value($amount, $this->signed($account->type, Money::sum($sums->map(fn ($s) => Sums::num($s->base_net)))), $currency->id);
                    $consolidated[$account->type][$account->id] = $this->row($account, Money::add($consolidated[$account->type][$account->id]['amount'] ?? '0', $base));
                }
            }
            $blocks[] = ['currency_id' => $currency->id, 'code' => $currency->code, ...$this->incomeBlock($sections)];
        }
        usort($blocks, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $result = ['currencies' => $blocks];
        if ($frame->consolidated) {
            $result['consolidated'] = $this->incomeBlock(['revenue' => array_values(array_filter($consolidated['revenue'], fn ($r) => ! Money::isZero($r['amount']))),
                'expense' => array_values(array_filter($consolidated['expense'], fn ($r) => ! Money::isZero($r['amount'])))]);
        }

        return $result;
    }

    /** @param  array{revenue: list<array>, expense: list<array>}  $sections */
    private function incomeBlock(array $sections): array
    {
        foreach ($sections as $k => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $sections[$k] = $rows;
        }
        $revenue = Money::sum(array_column($sections['revenue'], 'amount'));
        $expenses = Money::sum(array_column($sections['expense'], 'amount'));

        return ['revenue' => $sections['revenue'], 'total_revenue' => $revenue, 'expenses' => $sections['expense'], 'total_expenses' => $expenses,
            'net_profit' => Money::sub($revenue, $expenses)];
    }

    /** @param  array{asset: list<array>, liability: list<array>, equity: list<array>}  $sections */
    private function sheet(array $sections, string $profit): array
    {
        foreach ($sections as $k => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $sections[$k] = $rows;
        }
        $assets = Money::sum(array_column($sections['asset'], 'amount'));
        $liabilities = Money::sum(array_column($sections['liability'], 'amount'));
        $equity = Money::sum(array_column($sections['equity'], 'amount'));
        $right = Money::add(Money::add($liabilities, $equity), $profit);

        return ['assets' => $sections['asset'], 'total_assets' => $assets, 'liabilities' => $sections['liability'], 'total_liabilities' => $liabilities,
            'equity' => $sections['equity'], 'total_equity' => $equity, 'current_profit' => $profit, 'total_liabilities_equity_profit' => $right,
            'difference' => Money::sub($assets, $right), 'balanced' => Money::cmp($assets, $right) === 0];
    }
}
