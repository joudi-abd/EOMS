<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Period;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry core. Every entry has one currency and balances; currencies are never mixed or converted.
 * Posted entries are immutable: corrections are reversing entries. Closed months accept no entries.
 */
class LedgerService
{
    /** Whole-transaction retries on deadlocks and serialization conflicts (MariaDB 1020, PostgreSQL 40001/40P01). */
    private const TX_ATTEMPTS = 3;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Only these posting sources may debit/credit the system inventory account (and their reversals keep the same source). */
    private const INVENTORY_SOURCES = ['invoice', 'stock_move'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, party_id?: ?int, memo?: ?string}>  $lines
     */
    public function post(string $currency, string $date, string $description, array $lines, User $actor, ?string $sourceType = null, ?int $sourceId = null, ?int $reverses = null): JournalEntry
    {
        return DB::transaction(function () use ($currency, $date, $description, $lines, $actor, $sourceType, $sourceId, $reverses) {
            $this->ensureOpen($date);
            $debit = 0.0;
            $credit = 0.0;
            $rows = [];
            foreach ($lines as $i => $line) {
                $d = round((float) ($line['debit'] ?? 0), 2);
                $c = round((float) ($line['credit'] ?? 0), 2);
                if ($d < 0 || $c < 0 || ($d > 0) === ($c > 0)) {
                    throw ValidationException::withMessages(["lines.$i" => ['كل سطر مدين أو دائن بمبلغ موجب.']]);
                }
                $this->postable((int) $line['account_id'], "lines.$i");
                $debit += $d;
                $credit += $c;
                $rows[] = ['account_id' => (int) $line['account_id'], 'party_id' => $line['party_id'] ?? null,
                    'debit' => $d, 'credit' => $c, 'memo' => $line['memo'] ?? null];
            }
            // A manual entry may be posted unbalanced: the gap goes to the "entry differences" account and is kept on the entry
            // (+ = debit exceeded credit, a gain for the company; − = credit exceeded debit, in the customer's favour).
            $difference = null;
            if ($sourceType === 'manual' && $reverses === null && $rows !== [] && abs($debit - $credit) >= 0.005) {
                $difference = round($debit - $credit, 2);
                $rows[] = ['account_id' => $this->systemAccount('entry_differences')->id, 'party_id' => null,
                    'debit' => $difference < 0 ? -$difference : 0.0, 'credit' => $difference > 0 ? $difference : 0.0, 'memo' => 'فرق القيد'];
                $debit = $credit = max($debit, $credit);
            }
            if (count($rows) < 2 || abs($debit - $credit) >= 0.005) {
                throw ValidationException::withMessages(['lines' => ['القيد غير متوازن: مجموع المدين يجب أن يساوي مجموع الدائن.']]);
            }
            $this->guard($rows, $currency, $sourceType);
            $entry = JournalEntry::create(['number' => $this->number('JE', $date), 'date' => $date, 'currency_code' => strtoupper($currency),
                'description' => $description, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'reverses_id' => $reverses, 'difference' => $difference, 'created_by' => $actor->id]);
            $entry->lines()->createMany($rows);
            $this->audit->log('finance.entry_posted', $entry, null, ['number' => $entry->number, 'total' => round($debit, 2)], $actor->id);

            return $entry->load('lines');
        }, self::TX_ATTEMPTS);
    }

    /** Mirror entry dated in an open month; an entry can be reversed once, and a reversal is never reversed. */
    public function reverse(JournalEntry $entry, string $date, string $reason, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $reason, $actor) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            abort_if($entry->reverses_id !== null, 409, 'لا يُعكس قيد عكسي.');
            abort_if(JournalEntry::query()->where('reverses_id', $entry->id)->exists(), 409, 'القيد معكوس مسبقاً.');
            $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get()->map(fn (JournalLine $l) => [
                'account_id' => $l->account_id, 'party_id' => $l->party_id, 'debit' => $l->credit, 'credit' => $l->debit, 'memo' => $l->memo,
            ])->all();

            $reversal = $this->post($entry->currency_code, $date, 'عكس القيد '.$entry->number.': '.$reason, $lines, $actor,
                $entry->source_type, $entry->source_id, $entry->id);
            if ($entry->difference !== null) {
                $reversal->update(['difference' => -1 * (float) $entry->difference]);
            }

            return $reversal;
        }, self::TX_ATTEMPTS);
    }

    /**
     * Ledger-wide invariants, enforced for every posting path (operations, invoices, reversals, manual entries):
     * receivable/payable lines name their party; inventory moves only with stock documents; a box never goes negative.
     */
    private function guard(array $rows, string $currency, ?string $sourceType): void
    {
        $system = Account::query()->whereIn('id', array_column($rows, 'account_id'))->whereNotNull('system_key')->pluck('system_key', 'id');
        foreach ($rows as $i => $row) {
            $key = $system->get($row['account_id']);
            if (in_array($key, ['receivables', 'payables'], true) && empty($row['party_id'])) {
                throw ValidationException::withMessages(["lines.$i.party_id" => ['سطر ذمم العملاء أو الموردين يحتاج الطرف.']]);
            }
            if ($key === 'inventory' && ! in_array($sourceType, self::INVENTORY_SOURCES, true)) {
                throw ValidationException::withMessages(["lines.$i.account_id" => ['حساب المخزون يتحرك مع المواد فقط عبر فاتورة أو تسوية جرد.']]);
            }
        }
        $boxes = Box::query()->whereIn('account_id', array_column($rows, 'account_id'))->lockForUpdate()->get()->keyBy('account_id');
        foreach ($boxes as $accountId => $box) {
            abort_unless($box->currency_code === strtoupper($currency), 422, 'عملة القيد يجب أن تكون عملة الصندوق '.$box->name.'.');
            $change = collect($rows)->where('account_id', $accountId)->sum(fn ($r) => $r['debit'] - $r['credit']);
            if ($change < 0) {
                $balance = (float) JournalLine::query()->where('account_id', $accountId)->sum(DB::raw('debit - credit'));
                if ($balance + $change < -0.005) {
                    throw ValidationException::withMessages(['amount' => ['رصيد الصندوق '.$box->name.' غير كافٍ (المتاح '.number_format($balance, 2).').']]);
                }
            }
        }
    }

    /**
     * Refuses activity in a closed month. Locks the period row so close-approval and posting cannot race.
     */
    public function ensureOpen(string $date): void
    {
        $month = substr($date, 0, 7);
        $period = Period::query()->where('month', $month)->lockForUpdate()->first();
        if ($period && $period->status === 'closed') {
            throw ValidationException::withMessages(['date' => ["الشهر $month مقفل؛ سجّل التصحيح في شهر مفتوح."]]);
        }
    }

    public function systemAccount(string $key): Account
    {
        return Account::query()->where('system_key', $key)->firstOrFail();
    }

    /** Only active accounts without sub-accounts take postings. */
    public function postable(int $accountId, string $field = 'account_id'): Account
    {
        $account = Account::query()->find($accountId);
        if (! $account || ! $account->active || Account::query()->where('parent_id', $accountId)->exists()) {
            throw ValidationException::withMessages([$field => ['الحساب غير موجود أو غير فعّال أو له حسابات فرعية.']]);
        }

        return $account;
    }

    /**
     * Counterpart for a stock count adjustment: postable, not a box, and not a system control account
     * (inventory, receivables, payables, COGS, salaries, FX clearing, entry differences). Opening balances is
     * allowed: the stock on hand when the company starts using the system is entered against it.
     */
    public function stockAdjustmentCounterpart(int $accountId, string $field = 'account_id'): Account
    {
        $account = $this->postable($accountId, $field);
        if ($account->system_key !== null && $account->system_key !== 'opening_balances') {
            throw ValidationException::withMessages([$field => ['حساب المقابل لتسوية الجرد لا يكون حساب نظام؛ اختر مصروفاً أو إيراداً أو حقوق ملكية مناسباً.']]);
        }
        if (Box::query()->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([$field => ['لا تستخدم حساب صندوق كمقابل لتسوية الجرد.']]);
        }

        return $account;
    }

    public function createAccount(array $data, User $actor): Account
    {
        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['parent_id'])) {
                $parent = Account::query()->lockForUpdate()->findOrFail($data['parent_id']);
                $this->checkParent($parent, $data['type']);
            }
            $account = Account::create([...$data, 'code' => trim($data['code'])]);
            $this->audit->log('finance.account_created', $account, null, $account->getAttributes(), $actor->id);

            return $account;
        }, self::TX_ATTEMPTS);
    }

    /** Type never changes once created; system accounts can be renamed and moved but not deactivated. */
    public function updateAccount(Account $account, array $data, User $actor): Account
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $parent = Account::query()->lockForUpdate()->findOrFail($data['parent_id']);
                $this->checkParent($parent, $account->type);
                for ($walk = $parent; $walk; $walk = $walk->parent_id ? Account::find($walk->parent_id) : null) {
                    abort_if($walk->id === $account->id, 422, 'لا يمكن نقل الحساب تحت أحد فروعه.');
                }
            }
            abort_if(($data['active'] ?? true) === false && ($account->system_key || Box::query()->where('account_id', $account->id)->exists()),
                422, 'لا يمكن إيقاف حساب يعتمد عليه النظام أو صندوق.');
            $old = $account->getAttributes();
            $account->update($data);
            $this->audit->log('finance.account_updated', $account, $old, $account->getAttributes(), $actor->id);

            return $account->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function requestClose(string $month, User $actor): Period
    {
        return DB::transaction(function () use ($month, $actor) {
            abort_unless($month < now()->format('Y-m'), 422, 'يُقفل الشهر بعد انتهائه.');
            $period = Period::query()->lockForUpdate()->firstOrNew(['month' => $month]);
            abort_if(in_array($period->status, ['closed', 'close_requested'], true), 409, 'الشهر مقفل أو بانتظار الموافقة.');
            $this->assertMonthSettled($month);
            $period->fill(['status' => 'close_requested', 'requested_by' => $actor->id, 'decided_by' => null, 'note' => null])->save();
            $this->audit->log('finance.period_close_requested', $period, null, ['month' => $month], $actor->id);
            app(FinanceNotifier::class)->toApprovers('finance.period_close_requested', "طلب إقفال الشهر المالي $month بانتظار موافقتك.", ['month' => $month], $actor->id);

            return $period;
        }, self::TX_ATTEMPTS);
    }

    public function decideClose(string $month, bool $approve, ?string $note, User $actor): Period
    {
        return DB::transaction(function () use ($month, $approve, $note, $actor) {
            $period = Period::query()->lockForUpdate()->where('month', $month)->firstOrFail();
            abort_unless($period->status === 'close_requested', 409, 'لا يوجد طلب إقفال لهذا الشهر.');
            // Re-check under the period lock: activity may have arrived while approval was pending.
            if ($approve) {
                $this->assertMonthSettled($month);
            }
            $period->update(['status' => $approve ? 'closed' : 'open', 'decided_by' => $actor->id,
                'closed_at' => $approve ? now() : null, 'note' => $note]);
            $this->audit->log($approve ? 'finance.period_closed' : 'finance.period_close_rejected', $period, null, ['month' => $month], $actor->id);
            app(FinanceNotifier::class)->toUser($period->requested_by, $approve ? 'finance.period_closed' : 'finance.period_close_rejected',
                ($approve ? 'تمت الموافقة على إقفال ' : 'رُفض طلب إقفال ').$month.($note ? ': '.$note : '.'), ['month' => $month]);

            return $period;
        }, self::TX_ATTEMPTS);
    }

    /** Pending payments and draft/pending invoices in the month must be cleared before close request or approval. */
    private function assertMonthSettled(string $month): void
    {
        [$from, $to] = $this->bounds($month);
        // Lock the rows themselves (FOR UPDATE cannot wrap an aggregate on every engine), then count them.
        $pending = Transaction::query()->where('status', 'pending')->whereBetween('date', [$from, $to])->lockForUpdate()->pluck('id')->count()
            + Invoice::query()->whereIn('status', ['draft', 'pending'])->whereBetween('date', [$from, $to])->lockForUpdate()->pluck('id')->count();
        abort_if($pending > 0, 409, "توجد $pending عملية أو فاتورة غير محسومة في هذا الشهر.");
    }

    public function reopen(string $month, string $reason, User $actor): Period
    {
        return DB::transaction(function () use ($month, $reason, $actor) {
            $period = Period::query()->lockForUpdate()->where('month', $month)->where('status', 'closed')->firstOrFail();
            $period->update(['status' => 'open', 'decided_by' => $actor->id, 'closed_at' => null, 'note' => $reason]);
            $this->audit->log('finance.period_reopened', $period, null, ['month' => $month, 'reason' => $reason], $actor->id);

            return $period;
        }, self::TX_ATTEMPTS);
    }

    /** Sequential numbers per prefix and year, e.g. JE-2026-000001, S-2026-0001. */
    public function number(string $prefix, string $date, int $pad = 6): string
    {
        $year = (int) substr($date, 0, 4);
        DB::table('fin_sequences')->insertOrIgnore(['prefix' => $prefix, 'year' => $year, 'last' => 0]);
        $row = DB::table('fin_sequences')->where('prefix', $prefix)->where('year', $year)->lockForUpdate()->first();
        DB::table('fin_sequences')->where('id', $row->id)->update(['last' => $row->last + 1]);

        return sprintf('%s-%d-%0'.$pad.'d', $prefix, $year, $row->last + 1);
    }

    public function bounds(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /** Parent must match type, have no postings yet, and must not be a box or protected system account. */
    public function checkParent(Account $parent, string $type): void
    {
        abort_unless($parent->type === $type, 422, 'الحساب الفرعي يجب أن يكون من نوع الحساب الأب.');
        abort_if(JournalLine::query()->where('account_id', $parent->id)->exists(), 422, 'للحساب الأب حركات؛ لا يمكن أن يصبح حساباً تجميعياً.');
        abort_if(Box::query()->where('account_id', $parent->id)->exists() || $parent->system_key !== null,
            422, 'لا تُضاف حسابات فرعية تحت حساب صندوق أو حساب نظام.');
    }
}
