<?php

namespace App\Modules\Finance\Services\Treasury;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Carbon\CarbonImmutable;

/** Box statement (spec §9.2 "حركة صندوق", T2): per currency, the opening balance, each movement with its running balance, and the closing balance. */
class BoxStatementService
{
    public function __construct(private LedgerService $ledger) {}

    /** @return array{box: array, from: string, to: string, currencies: list<array<string, mixed>>} */
    public function statement(CashBox $box, string $from, string $to, ?int $currencyId = null): array
    {
        $currencyIds = CashBoxCurrency::query()->where('cash_box_id', $box->id)->pluck('currency_id')
            ->merge(JournalLine::query()->where('account_id', $box->account_id)->distinct()->pluck('currency_id'))
            ->map(fn ($id) => (int) $id)->unique()->when($currencyId !== null, fn ($c) => $c->filter(fn ($id) => $id === $currencyId))->values();
        $currencies = Currency::query()->whereIn('id', $currencyIds)->orderBy('code')->get();
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();
        $result = [];
        foreach ($currencies as $currency) {
            $opening = Money::round($this->ledger->balanceOn($box->account_id, $currency->id, $dayBefore), $currency->decimals);
            $running = $opening;
            $lines = JournalLine::query()->join('fin_journal_entries as e', 'e.id', '=', 'fin_journal_lines.journal_entry_id')
                ->where('fin_journal_lines.account_id', $box->account_id)->where('fin_journal_lines.currency_id', $currency->id)
                ->whereBetween('fin_journal_lines.entry_date', [$from, $to])
                ->orderBy('fin_journal_lines.entry_date')->orderBy('fin_journal_lines.journal_entry_id')->orderBy('fin_journal_lines.line_no')
                ->get(['fin_journal_lines.*', 'e.number as entry_number', 'e.description as entry_description', 'e.source_type', 'e.source_id']);
            $vouchers = Voucher::query()->whereIn('id', $lines->where('source_type', 'voucher')->pluck('source_id')->unique())->get(['id', 'number', 'type', 'purpose'])->keyBy('id');
            $rows = [];
            foreach ($lines as $line) {
                $running = Money::add($running, Money::sub((string) $line->debit, (string) $line->credit));
                $voucher = $line->source_type === 'voucher' ? $vouchers->get($line->source_id) : null;
                $rows[] = ['date' => $line->entry_date->toDateString(), 'entry_number' => $line->entry_number, 'voucher_id' => $voucher?->id,
                    'voucher_number' => $voucher?->number, 'voucher_type' => $voucher?->type, 'description' => $line->entry_description, 'memo' => $line->memo,
                    'debit' => Money::of((string) $line->debit), 'credit' => Money::of((string) $line->credit), 'balance' => $running];
            }
            $result[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'opening' => $opening, 'lines' => $rows,
                'total_in' => Money::sum(array_column($rows, 'debit')), 'total_out' => Money::sum(array_column($rows, 'credit')), 'closing' => $running];
        }

        return ['box' => $box->only(['id', 'name', 'type', 'branch_id']), 'from' => $from, 'to' => $to, 'currencies' => $result];
    }
}
