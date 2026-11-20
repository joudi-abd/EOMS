<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountBalance;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Posting\EntryDraft;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The double-entry core. Lines carry their own currency; every entry balances in each currency (R2) and nothing is
 * ever auto-balanced. Each line keeps the bulletin rate on or before the entry date and its base (USD) equivalent.
 * Posted entries are immutable: corrections are reversing entries dated in an open month (R1).
 *
 * Lock order (every write path): period row → cash box rows (ascending id) → balance rows (ascending key).
 */
class LedgerService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private PeriodService $periods, private Sequences $sequences, private ExchangeRateService $rates,
        private AuditLogger $audit) {}

    /** Validates, numbers and writes the entry, updates balances and checks the cash-box floors. */
    public function postEntry(EntryDraft $draft, User $actor): JournalEntry
    {
        return DB::transaction(fn () => $this->write($draft, $actor, null), self::TX_ATTEMPTS);
    }

    /**
     * Mirror entry (debit ↔ credit, same rates and dimensions) dated `$date` in an open month. An entry is reversed
     * once; a reversal is never reversed. The cash-box floor applies to the reversal too.
     */
    public function reverseEntry(JournalEntry $entry, string $date, string $reason, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $reason, $actor) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            abort_if($entry->reversal_of_id !== null, 409, 'لا يُعكس قيد عكسي.');
            abort_if($entry->status === 'reversed', 409, 'القيد معكوس مسبقاً.');
            if ($date < $entry->entry_date->toDateString()) {
                throw ValidationException::withMessages(['date' => ['تاريخ العكس لا يسبق تاريخ القيد.']]);
            }
            $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->orderBy('line_no')->get()
                ->map(fn (JournalLine $l) => new LineDraft($l->account_id, $l->currency_id, (string) $l->credit, (string) $l->debit,
                    array_intersect_key($l->getAttributes(), array_flip(LineDraft::DIMENSIONS)), $l->memo, $l->deal_rate === null ? null : (string) $l->deal_rate,
                    (string) $l->rate_to_base, $l->branch_id))
                ->all();
            $draft = new EntryDraft($date, $entry->branch_id, Str::limit('عكس القيد '.$entry->number.': '.$reason, 495), $lines,
                $entry->source_type, $entry->source_id);
            $reversal = $this->write($draft, $actor, $entry);
            $entry->update(['status' => 'reversed']);
            $this->audit->log('finance.entry_reversed', $entry, ['status' => 'posted'], ['status' => 'reversed', 'reversal' => $reversal->number,
                'reason' => $reason], $actor->id);

            return $reversal;
        }, self::TX_ATTEMPTS);
    }

    /** Balance (debit − credit) of an account in a currency; of one party when given, of all parties otherwise. */
    public function balance(int $accountId, int $currencyId, ?int $partyId = null): string
    {
        $query = AccountBalance::query()->where('account_id', $accountId)->where('currency_id', $currencyId);
        if ($partyId !== null) {
            $query->where('party_key', $partyId);
        }

        return Money::round(Money::of($query->sum('balance')), Money::SCALE);
    }

    /**
     * The lowest running balance the account reaches in the currency on `$date` or any later day: what may leave on
     * `$date` without the statement dipping below the floor on some later day (OP-13).
     */
    public function lowestBalanceFrom(int $accountId, int $currencyId, string $date): string
    {
        $lines = fn () => JournalLine::query()->where('account_id', $accountId)->where('currency_id', $currencyId);
        $running = Money::round(Money::of($lines()->where('entry_date', '<=', $date)->sum(DB::raw('debit - credit'))), Money::SCALE);
        $lowest = $running;
        $later = $lines()->where('entry_date', '>', $date)->groupBy('entry_date')->orderBy('entry_date')
            ->selectRaw('entry_date, SUM(debit - credit) as net')->pluck('net');
        foreach ($later as $net) {
            $running = Money::add($running, Money::round(Money::of($net), Money::SCALE));
            $lowest = Money::min($lowest, $running);
        }

        return $lowest;
    }

    /** Balance of the account in the currency at the end of `$date` (from the lines). */
    public function balanceOn(int $accountId, int $currencyId, string $date): string
    {
        return Money::round(Money::of(JournalLine::query()->where('account_id', $accountId)->where('currency_id', $currencyId)
            ->where('entry_date', '<=', $date)->sum(DB::raw('debit - credit'))), Money::SCALE);
    }

    private function write(EntryDraft $draft, User $actor, ?JournalEntry $reversalOf): JournalEntry
    {
        $reversing = $reversalOf !== null;
        $period = $this->periods->ensureOpen($draft->date);
        if (count($draft->lines) < 2) {
            throw ValidationException::withMessages(['lines' => ['القيد يحتاج سطرين على الأقل.']]);
        }
        $currencies = $this->currencies($draft->lines);
        $accounts = Account::query()->whereIn('id', array_map(fn (LineDraft $l) => $l->accountId, $draft->lines))->get()->keyBy('id');
        $warnings = [];
        $rows = [];
        foreach (array_values($draft->lines) as $i => $line) {
            $account = $accounts->get($line->accountId);
            $currency = $currencies[$line->currency];
            if ($account === null) {
                throw ValidationException::withMessages(["lines.$i.account_id" => ['الحساب غير موجود.']]);
            }
            if (! $reversing) {
                $this->checkLine($account, $currency, $line, $i);
            }
            $debit = Money::round($line->debit, $currency->decimals);
            $credit = Money::round($line->credit, $currency->decimals);
            if (Money::isNegative($debit) || Money::isNegative($credit) || Money::isPositive($debit) === Money::isPositive($credit)) {
                throw ValidationException::withMessages(["lines.$i" => ['كل سطر مدين أو دائن بمبلغ موجب.']]);
            }
            $rows[] = ['line' => $line, 'account' => $account, 'currency' => $currency, 'debit' => $debit, 'credit' => $credit];
        }
        if (! $reversing) {
            $warnings = $this->checkDimensions($draft->lines);
        }
        foreach (collect($rows)->groupBy(fn ($r) => $r['currency']->id) as $group) {
            if (Money::cmp(Money::sum($group->pluck('debit')), Money::sum($group->pluck('credit'))) !== 0) {
                throw ValidationException::withMessages(['lines' => ['القيد غير متوازن في العملة '.$group->first()['currency']->code.'.']]);
            }
        }
        $rates = $reversing ? [] : $this->ratesFor($currencies, $draft->date);

        // Lock the boxes before touching balances, in id order, so two postings on the same boxes queue up.
        $boxes = CashBox::query()->whereIn('account_id', $accounts->keys()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('account_id');

        $entry = JournalEntry::create([
            'number' => $this->sequences->next('journal_entry', $draft->date), 'entry_date' => $draft->date, 'period_id' => $period->id,
            'branch_id' => $draft->branchId, 'source_type' => $draft->sourceType, 'source_id' => $draft->sourceId,
            'description' => Str::limit($draft->description, 495) ?: '-', 'status' => 'posted', 'reversal_of_id' => $reversalOf?->id,
            'posted_by' => $actor->id, 'posted_at' => now(),
        ]);
        $insert = [];
        foreach ($rows as $i => $row) {
            /** @var LineDraft $line */
            $line = $row['line'];
            $rate = $line->rateToBase ?? $rates[$row['currency']->id];
            $insert[] = [
                'journal_entry_id' => $entry->id, 'line_no' => $i + 1, 'account_id' => $row['account']->id, 'currency_id' => $row['currency']->id,
                'debit' => $row['debit'], 'credit' => $row['credit'], 'rate_to_base' => $rate,
                'base_debit' => Money::isZero($row['debit']) ? '0' : Money::div($row['debit'], $rate, Money::SCALE),
                'base_credit' => Money::isZero($row['credit']) ? '0' : Money::div($row['credit'], $rate, Money::SCALE),
                'deal_rate' => $line->dealRate, 'entry_date' => $draft->date, 'branch_id' => $line->branchId ?? $draft->branchId,
                ...$line->dims, 'memo' => $line->memo === null ? null : Str::limit($line->memo, 495),
            ];
        }
        JournalLine::query()->insert($insert);
        $this->applyBalances($insert);
        $this->guardBoxes($boxes, $insert, $draft->date, $currencies, $reversing);

        if (! $reversing) {
            $this->audit->log('finance.entry_posted', $entry, null, ['number' => $entry->number, 'source' => $draft->sourceType.':'.$draft->sourceId], $actor->id);
        }
        $entry->warnings = $warnings;

        return $entry;
    }

    /** R4 and the account's own rules, for a new (not reversing) line. */
    private function checkLine(Account $account, Currency $currency, LineDraft $line, int $i): void
    {
        $field = "lines.$i.account_id";
        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => ["الحساب {$account->code} {$account->name} غير فعّال."]]);
        }
        if (! $account->is_postable) {
            throw ValidationException::withMessages([$field => ["الحساب {$account->code} {$account->name} تجميعي ولا يقبل قيوداً."]]);
        }
        if ($account->requires_party && $line->dims['party_id'] === null) {
            throw ValidationException::withMessages(["lines.$i.party_id" => ["الحساب {$account->name} يتطلب جهة تعامل."]]);
        }
        if ($account->requires_project && $line->dims['project_id'] === null) {
            throw ValidationException::withMessages(["lines.$i.project_id" => ["الحساب {$account->name} يتطلب مشروعاً."]]);
        }
        if (! $currency->is_active) {
            throw ValidationException::withMessages(["lines.$i.currency" => ["العملة {$currency->code} غير مفعّلة."]]);
        }
    }

    /**
     * Parties must be active (R4); a closed project takes no postings (R5) and a completed one only with a warning.
     *
     * @param  list<LineDraft>  $lines
     * @return list<string>
     */
    private function checkDimensions(array $lines): array
    {
        $partyIds = array_values(array_unique(array_filter(array_map(fn (LineDraft $l) => $l->dims['party_id'], $lines))));
        if ($partyIds !== []) {
            $inactive = Party::query()->whereIn('id', $partyIds)->where('is_active', false)->value('name');
            if ($inactive !== null) {
                throw ValidationException::withMessages(['party_id' => ["جهة التعامل {$inactive} غير فعّالة."]]);
            }
            if (Party::query()->whereIn('id', $partyIds)->count() !== count($partyIds)) {
                throw ValidationException::withMessages(['party_id' => ['جهة التعامل غير موجودة.']]);
            }
        }
        $warnings = [];
        $projectIds = array_values(array_unique(array_filter(array_map(fn (LineDraft $l) => $l->dims['project_id'], $lines))));
        if ($projectIds !== []) {
            $projects = app(ProjectService::class)->query()->whereIn('id', $projectIds)->get(['id', 'name', 'status']);
            if ($projects->count() !== count($projectIds)) {
                throw ValidationException::withMessages(['project_id' => ['المشروع غير موجود.']]);
            }
            foreach ($projects as $project) {
                if ($project->status === 'closed') {
                    throw ValidationException::withMessages(['project_id' => ["المشروع {$project->name} مغلق ولا يقبل حركات."]]);
                }
                if ($project->status === 'completed') {
                    $warnings[] = "المشروع {$project->name} مكتمل؛ تأكد أن الحركة مالية فقط.";
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  list<LineDraft>  $lines
     * @return array<int|string, Currency> keyed by what the lines used (id or code)
     */
    private function currencies(array $lines): array
    {
        $map = [];
        $all = Currency::query()->get();
        foreach ($lines as $i => $line) {
            if (isset($map[$line->currency])) {
                continue;
            }
            $currency = is_int($line->currency) ? $all->firstWhere('id', $line->currency) : $all->firstWhere('code', strtoupper($line->currency));
            if ($currency === null) {
                throw ValidationException::withMessages(["lines.$i.currency" => ['العملة غير موجودة.']]);
            }
            $map[$line->currency] = $currency;
        }

        return $map;
    }

    /**
     * Bulletin rate on or before the entry date per currency (the base currency is 1).
     *
     * @param  array<int|string, Currency>  $currencies
     * @return array<int, string> currency id => rate
     */
    private function ratesFor(array $currencies, string $date): array
    {
        $rates = [];
        foreach ($currencies as $currency) {
            if (isset($rates[$currency->id])) {
                continue;
            }
            $found = $this->rates->rateToBaseOnOrBefore($currency->code, $date);
            if ($found === null) {
                throw ValidationException::withMessages(['date' => ["لا يوجد سعر لـ {$currency->code} في النشرة حتى تاريخ {$date}؛ أدخل النشرة أولاً."]]);
            }
            $rates[$currency->id] = $found['rate'];
        }

        return $rates;
    }

    /** Adds each line's debit − credit to its (account, currency, party) balance row, rows locked in key order. */
    private function applyBalances(array $lines): void
    {
        $deltas = [];
        foreach ($lines as $line) {
            $key = sprintf('%020d|%020d|%020d', $line['account_id'], $line['currency_id'], $line['party_id'] ?? 0);
            $deltas[$key] = Money::add($deltas[$key] ?? '0', Money::sub($line['debit'], $line['credit']));
        }
        ksort($deltas);
        foreach ($deltas as $key => $delta) {
            [$account, $currency, $party] = array_map('intval', explode('|', $key));
            $keys = ['account_id' => $account, 'currency_id' => $currency, 'party_key' => $party];
            AccountBalance::query()->insertOrIgnore($keys + ['balance' => '0', 'updated_at' => now()]);
            $row = AccountBalance::query()->where($keys)->lockForUpdate()->firstOrFail();
            AccountBalance::query()->whereKey($row->id)->update(['balance' => Money::add((string) $row->balance, $delta), 'updated_at' => now()]);
        }
    }

    /**
     * R7: a box line's currency must be enabled on the box. R8 + OP-13: money leaving a box may not take its running
     * balance, on the entry date or any later day, below the floor: 0 when negative is not allowed, −limit when it is
     * allowed with a limit, none when it is allowed without one.
     *
     * @param  Collection<int, CashBox>  $boxes  keyed by account id
     * @param  array<int|string, Currency>  $currencies
     */
    private function guardBoxes(Collection $boxes, array $lines, string $date, array $currencies, bool $reversing): void
    {
        if ($boxes->isEmpty()) {
            return;
        }
        $byId = collect($currencies)->keyBy('id');
        $settings = CashBoxCurrency::query()->whereIn('cash_box_id', $boxes->pluck('id'))->get()->groupBy('cash_box_id');
        foreach ($boxes as $accountId => $box) {
            $boxLines = collect($lines)->where('account_id', $accountId)->groupBy('currency_id');
            foreach ($boxLines as $currencyId => $group) {
                $currency = $byId->get($currencyId);
                $setting = $settings->get($box->id)?->firstWhere('currency_id', (int) $currencyId);
                if ($setting === null && ! $reversing) {
                    throw ValidationException::withMessages(['currency_id' => ["العملة {$currency->code} غير مفعّلة في الصندوق {$box->name}."]]);
                }
                $delta = Money::sub(Money::sum($group->pluck('debit')), Money::sum($group->pluck('credit')));
                if (! Money::isNegative($delta)) {
                    continue;
                }
                $floor = match (true) {
                    $setting === null || ! $setting->allow_negative => '0',
                    $setting->negative_limit === null => null,
                    default => Money::neg((string) $setting->negative_limit),
                };
                if ($floor === null) {
                    continue;
                }
                $lowest = $this->lowestBalanceFrom((int) $accountId, (int) $currencyId, $date);
                if (Money::cmp($lowest, $floor) < 0) {
                    $current = Money::sub($this->balance((int) $accountId, (int) $currencyId), $delta);
                    $available = Money::max('0', Money::sub(Money::sub($lowest, $delta), $floor));
                    throw ValidationException::withMessages(['amount' => ["رصيد الصندوق {$box->name} بعملة {$currency->code} لا يكفي: الرصيد الحالي "
                        .Money::format($current, $currency->decimals).'، المتاح '.Money::format($available, $currency->decimals).'.']]);
                }
            }
        }
    }
}
