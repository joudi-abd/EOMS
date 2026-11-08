<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Party;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reports per currency only: currencies are never converted or combined. */
class ReportService
{
    public function __construct(private LedgerService $ledger) {}

    public function boxStatement(Box $box, string $from, string $to): array
    {
        return [...$this->statement(fn (Builder $q) => $q->where('l.account_id', $box->account_id), $box->currency_code, $from, $to, true),
            'box' => $box->only(['id', 'name', 'kind', 'currency_code'])];
    }

    public function ledger(Account $account, string $currency, string $from, string $to): array
    {
        $debitNature = in_array($account->type, ['asset', 'expense'], true);

        return [...$this->statement(fn (Builder $q) => $q->where('l.account_id', $account->id), $currency, $from, $to, $debitNature),
            'account' => $account->only(['id', 'code', 'name', 'type'])];
    }

    /** What the party owes (customer) or is owed (supplier), with open and overdue invoices. */
    public function partyStatement(Party $party, string $currency, string $from, string $to): array
    {
        $key = $party->kind === 'customer' ? 'receivables' : 'payables';
        $account = $this->ledger->systemAccount($key)->id;
        $statement = $this->statement(fn (Builder $q) => $q->where('l.account_id', $account)->where('l.party_id', $party->id),
            $currency, $from, $to, $party->kind === 'customer');
        $today = now()->toDateString();
        $open = Invoice::query()->where('party_id', $party->id)->where('currency_code', $currency)->where('status', 'posted')
            ->whereColumn('paid', '<', 'total')->orderBy('due_date')->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'date' => $i->date->toDateString(), 'due_date' => $i->due_date->toDateString(),
                'total' => $i->total, 'paid' => $i->paid, 'remaining' => round((float) $i->total - (float) $i->paid, 2),
                'overdue' => $i->due_date->toDateString() < $today,
                'days_overdue' => $i->due_date->toDateString() < $today ? (int) $i->due_date->diffInDays(now()->startOfDay()) : 0,
            ]);

        return [...$statement, 'party' => $party->only(['id', 'kind', 'name']), 'open_invoices' => $open,
            'overdue_total' => round($open->where('overdue', true)->sum('remaining'), 2)];
    }

    public function trialBalance(string $currency, string $from, string $to): array
    {
        $rows = $this->totals($currency, $from, $to)->map(fn ($r) => [...(array) $r,
            'balance' => round((float) $r->debit - (float) $r->credit, 2)]);

        return ['currency' => $currency, 'from' => $from, 'to' => $to, 'accounts' => $rows->values(),
            'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2)];
    }

    public function incomeStatement(string $currency, string $from, string $to): array
    {
        $rows = $this->totals($currency, $from, $to)->whereIn('type', ['revenue', 'expense']);
        $revenue = $rows->where('type', 'revenue')->map(fn ($r) => [...(array) $r, 'amount' => round((float) $r->credit - (float) $r->debit, 2)])->values();
        $expense = $rows->where('type', 'expense')->map(fn ($r) => [...(array) $r, 'amount' => round((float) $r->debit - (float) $r->credit, 2)])->values();

        return ['currency' => $currency, 'from' => $from, 'to' => $to, 'revenue' => $revenue, 'expenses' => $expense,
            'total_revenue' => round($revenue->sum('amount'), 2), 'total_expenses' => round($expense->sum('amount'), 2),
            'net_profit' => round($revenue->sum('amount') - $expense->sum('amount'), 2)];
    }

    /** As of a date: assets = liabilities + equity + profit to date (no closing entries are needed). */
    public function balanceSheet(string $currency, string $asOf): array
    {
        $rows = $this->totals($currency, '1900-01-01', $asOf);
        $section = fn (string $type, bool $debitNature) => $rows->where('type', $type)->map(fn ($r) => [...(array) $r,
            'amount' => round($debitNature ? (float) $r->debit - (float) $r->credit : (float) $r->credit - (float) $r->debit, 2)])->values();
        $assets = $section('asset', true);
        $liabilities = $section('liability', false);
        $equity = $section('equity', false);
        $profit = round($section('revenue', false)->sum('amount') - $section('expense', true)->sum('amount'), 2);

        return ['currency' => $currency, 'as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'profit_to_date' => $profit, 'total_assets' => round($assets->sum('amount'), 2),
            'total_liabilities_and_equity' => round($liabilities->sum('amount') + $equity->sum('amount') + $profit, 2)];
    }

    private function totals(string $currency, string $from, string $to)
    {
        return DB::table('fin_journal_lines as l')->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.currency_code', $currency)->whereBetween('e.date', [$from, $to])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, ROUND(SUM(l.debit), 2) as debit, ROUND(SUM(l.credit), 2) as credit')
            ->get()->map(function ($r) {
                $r->debit = (float) $r->debit;
                $r->credit = (float) $r->credit;

                return $r;
            });
    }

    /** Opening balance, movements with running balance, closing balance. */
    private function statement(callable $scope, string $currency, string $from, string $to, bool $debitNature): array
    {
        $base = fn () => tap(DB::table('fin_journal_lines as l')->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.currency_code', $currency), $scope);
        $sign = $debitNature ? 1 : -1;
        $opening = round($sign * (float) $base()->where('e.date', '<', $from)->sum(DB::raw('l.debit - l.credit')), 2);
        $running = $opening;
        $lines = $base()->whereBetween('e.date', [$from, $to])->orderBy('e.date')->orderBy('e.id')->orderBy('l.id')
            ->get(['e.id as entry_id', 'e.number', 'e.date', 'e.description', 'l.debit', 'l.credit', 'l.memo', 'e.source_type', 'e.source_id'])
            ->map(function ($l) use (&$running, $sign) {
                $running = round($running + $sign * ((float) $l->debit - (float) $l->credit), 2);

                return [...(array) $l, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'balance' => $running];
            });

        return ['currency' => $currency, 'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'lines' => $lines,
            'closing_balance' => $running];
    }
}
