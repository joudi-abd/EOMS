<?php

namespace App\Modules\Finance\Services\Treasury;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Models\CashCountLine;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Services\FinanceNotifier;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Periodic box counts (D24). The counter enters what is in the box per currency and submits: the system balance is
 * captured then (R23). The difference posts (count_adjustment, #28) only when someone holding
 * `finance.counts.approve` who is not the counter approves it (R22). If the box moved after submit, approval waits
 * for a fresh capture.
 */
class CashCountService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private LedgerService $ledger, private VoucherService $vouchers, private PostingService $posting,
        private FinanceScope $scope, private FinanceNotifier $notifier, private AuditLogger $audit) {}

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        return $this->scope->apply(CashCount::query(), $user, 'finance.view')->with('lines')
            ->when($filters['box_id'] ?? null, fn ($q, $v) => $q->where('cash_box_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('count_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('count_date', '<=', $v))
            ->orderByDesc('count_date')->orderByDesc('id')->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
    }

    public function present(CashCount $count): array
    {
        $count->load(['lines', 'attachments']);

        return [...$count->toArray(), 'stale' => $count->status === 'submitted' && $this->isStale($count)];
    }

    /** @param  array{cash_box_id: int, count_date: string, lines: list<array>, notes?: ?string}  $data */
    public function create(array $data, User $actor): CashCount
    {
        return DB::transaction(function () use ($data, $actor) {
            $box = CashBox::query()->findOrFail($data['cash_box_id']);
            abort_unless($box->is_active, 422, 'الصندوق غير فعّال.');
            $this->scope->assertBranch($actor, 'finance.counts', $box->branch_id);
            $count = CashCount::create(['cash_box_id' => $box->id, 'branch_id' => $box->branch_id, 'count_date' => $data['count_date'],
                'status' => 'draft', 'counted_by' => $actor->id, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->writeLines($count, $box, $data['lines']);
            $this->audit->log('finance.cash_count_created', $count, null, $count->getAttributes(), $actor->id);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(CashCount $count, array $data, User $actor): CashCount
    {
        return DB::transaction(function () use ($count, $data, $actor) {
            $count = $this->lock($count, 'draft');
            $this->scope->assertBranch($actor, 'finance.counts', $count->branch_id);
            abort_unless($count->counted_by === $actor->id, 403, 'يعدّل الجرد من أجراه.');
            $old = $count->getAttributes();
            $count->update([...array_intersect_key($data, array_flip(['count_date', 'notes'])), 'updated_by' => $actor->id]);
            if (isset($data['lines'])) {
                CashCountLine::query()->where('cash_count_id', $count->id)->delete();
                $this->writeLines($count, CashBox::query()->findOrFail($count->cash_box_id), $data['lines']);
            }
            $this->audit->log('finance.cash_count_updated', $count, $old, $count->getAttributes(), $actor->id);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function delete(CashCount $count, User $actor): void
    {
        DB::transaction(function () use ($count, $actor) {
            $count = $this->lock($count, 'draft');
            $this->scope->assertBranch($actor, 'finance.counts', $count->branch_id);
            $this->audit->log('finance.cash_count_deleted', $count, $count->getAttributes(), null, $actor->id);
            $count->delete();
        }, self::TX_ATTEMPTS);
    }

    /** Captures the system balance of each counted currency and asks an approver to decide. */
    public function submit(CashCount $count, User $actor): CashCount
    {
        return DB::transaction(function () use ($count, $actor) {
            $count = $this->lock($count, 'draft');
            $this->scope->assertBranch($actor, 'finance.counts', $count->branch_id);
            $this->snapshot($count);
            $count->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_by' => $actor->id]);
            $this->audit->log('finance.cash_count_submitted', $count, ['status' => 'draft'], ['status' => 'submitted'], $actor->id);
            $box = CashBox::query()->findOrFail($count->cash_box_id);
            $this->notifier->toPermission('finance.counts.approve', 'finance.cash_count_submitted', 'جرد الصندوق '.$box->name.' بتاريخ '
                .$count->count_date->toDateString().' بانتظار اعتمادك.', ['cash_count_id' => $count->id], $actor->id);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** R23: after the box moved, the counter captures the balances again before approval. */
    public function resnapshot(CashCount $count, User $actor): CashCount
    {
        return DB::transaction(function () use ($count, $actor) {
            $count = $this->lock($count, 'submitted');
            $this->scope->assertBranch($actor, 'finance.counts', $count->branch_id);
            $this->snapshot($count);
            $count->update(['submitted_at' => now(), 'updated_by' => $actor->id]);
            $this->audit->log('finance.cash_count_resnapshot', $count, null, ['snapshot_entry_id' => $count->snapshot_entry_id], $actor->id);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** R22: the approver is not the counter. A non-zero difference posts a count_adjustment voucher. */
    public function approve(CashCount $count, User $actor): CashCount
    {
        return DB::transaction(function () use ($count, $actor) {
            $count = $this->lock($count, 'submitted');
            abort_if($count->counted_by === $actor->id, 403, 'لا يعتمد الجرد من أجراه.');
            $this->scope->assertBranch($actor, 'finance.counts.approve', $count->branch_id);
            abort_if($this->isStale($count), 409, 'تحرّك الصندوق بعد إرسال الجرد؛ أعد التقاط الأرصدة قبل الاعتماد.');
            $differences = CashCountLine::query()->where('cash_count_id', $count->id)->orderBy('currency_id')->get()
                ->filter(fn (CashCountLine $l) => ! Money::isZero((string) $l->difference));
            $voucherId = null;
            if ($differences->isNotEmpty()) {
                $voucher = $this->vouchers->createInternal([
                    'type' => 'count_adjustment', 'voucher_date' => $count->count_date->toDateString(), 'branch_id' => $count->branch_id,
                    'cash_box_id' => $count->cash_box_id, 'description' => 'فرق جرد الصندوق بتاريخ '.$count->count_date->toDateString(),
                    'meta' => ['cash_count_id' => $count->id],
                    'lines' => $differences->map(fn (CashCountLine $l) => ['currency_id' => $l->currency_id,
                        'debit' => Money::isPositive((string) $l->difference) ? (string) $l->difference : '0',
                        'credit' => Money::isNegative((string) $l->difference) ? Money::abs((string) $l->difference) : '0',
                        'memo' => $l->explanation])->values()->all(),
                ], $actor);
                $voucherId = $this->posting->approve($voucher, $actor)->id;
            }
            $count->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'voucher_id' => $voucherId]);
            $this->audit->log('finance.cash_count_approved', $count, ['status' => 'submitted'], ['status' => 'approved', 'voucher_id' => $voucherId], $actor->id);
            $this->notifier->toUser($count->counted_by, 'finance.cash_count_approved', 'اعتُمد الجرد بتاريخ '.$count->count_date->toDateString().'.', ['cash_count_id' => $count->id]);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function reject(CashCount $count, string $reason, User $actor): CashCount
    {
        return DB::transaction(function () use ($count, $reason, $actor) {
            $count = $this->lock($count, 'submitted');
            $this->scope->assertBranch($actor, 'finance.counts.approve', $count->branch_id);
            $count->update(['status' => 'rejected', 'rejected_reason' => $reason, 'updated_by' => $actor->id]);
            $this->audit->log('finance.cash_count_rejected', $count, ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason], $actor->id);
            $this->notifier->toUser($count->counted_by, 'finance.cash_count_rejected', 'رُفض الجرد بتاريخ '.$count->count_date->toDateString().': '.$reason, ['cash_count_id' => $count->id]);

            return $count->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** True when an entry touched the box after the balances were captured. */
    public function isStale(CashCount $count): bool
    {
        return $this->lastEntryOnBox($count->cash_box_id) > (int) $count->snapshot_entry_id;
    }

    private function snapshot(CashCount $count): void
    {
        $box = CashBox::query()->findOrFail($count->cash_box_id);
        foreach (CashCountLine::query()->where('cash_count_id', $count->id)->get() as $line) {
            $decimals = (int) Currency::query()->whereKey($line->currency_id)->value('decimals');
            $system = Money::round($this->ledger->balanceOn($box->account_id, $line->currency_id, $count->count_date->toDateString()), $decimals);
            $line->update(['system_balance' => $system, 'difference' => Money::sub((string) $line->counted_amount, $system)]);
        }
        $count->update(['snapshot_entry_id' => $this->lastEntryOnBox($box->id)]);
    }

    private function lastEntryOnBox(int $boxId): int
    {
        $accountId = CashBox::query()->whereKey($boxId)->value('account_id');

        return (int) JournalLine::query()->where('account_id', $accountId)->max('journal_entry_id');
    }

    /** @param  list<array{currency_id?: int, currency?: string, counted_amount: mixed, explanation?: ?string}>  $lines */
    private function writeLines(CashCount $count, CashBox $box, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['أدخل المعدود لعملة واحدة على الأقل.']]);
        }
        $enabled = CashBoxCurrency::query()->where('cash_box_id', $box->id)->pluck('currency_id')->map(fn ($id) => (int) $id)->all();
        $seen = [];
        foreach (array_values($lines) as $i => $line) {
            $currency = isset($line['currency_id']) ? Currency::query()->find($line['currency_id']) : Currency::query()->where('code', strtoupper((string) ($line['currency'] ?? '')))->first();
            if ($currency === null || ! in_array($currency->id, $enabled, true) || in_array($currency->id, $seen, true)) {
                throw ValidationException::withMessages(["lines.$i.currency_id" => ['العملة غير مفعّلة في الصندوق أو مكررة.']]);
            }
            $seen[] = $currency->id;
            CashCountLine::create(['cash_count_id' => $count->id, 'currency_id' => $currency->id,
                'counted_amount' => Money::round(Money::of($line['counted_amount']), $currency->decimals), 'explanation' => $line['explanation'] ?? null]);
        }
    }

    private function lock(CashCount $count, string $status): CashCount
    {
        $count = CashCount::query()->lockForUpdate()->findOrFail($count->id);
        abort_unless($count->status === $status, 409, $status === 'draft' ? 'الجرد ليس مسودة.' : 'الجرد ليس بانتظار الاعتماد.');

        return $count;
    }
}
