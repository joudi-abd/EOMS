<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\PosterRegistry;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\BranchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voucher drafts and their life cycle: create / update (draft only, optimistic `version`) / delete (draft only) /
 * submit (site-request style) / reject (submitted) / approve (posts) / reverse. Finance approves its own vouchers
 * (D25, OP-14: no four-eyes on daily vouchers). `approve: true` on create posts in the same call.
 * Vouchers made by other flows (cash count difference, payroll) go through createInternal().
 */
class VoucherService
{
    private const TX_ATTEMPTS = 3;

    /** Types only other flows create (allocation: /allocations, which enforces one per period and branch). */
    public const AUTOMATIC = ['count_adjustment', 'payroll_accrual', 'payroll_payment', 'allocation'];

    /** Fields copied from the payload as they are. */
    private const FIELDS = ['type', 'purpose', 'voucher_date', 'branch_id', 'cash_box_id', 'counter_cash_box_id', 'party_id', 'project_id',
        'project_cost_category_id', 'expense_category_id', 'asset_id', 'employee_id', 'contractor_agreement_id', 'custody_id', 'account_id',
        'classification', 'debt_direction', 'override_reason', 'description', 'reference_no', 'meta'];

    public function __construct(private PostingService $posting, private PosterRegistry $registry, private FinanceScope $scope,
        private AttachmentService $attachments, private BranchService $branches, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $query = $this->scope->apply(Voucher::query(), $user, 'finance.view')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['box_id'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('cash_box_id', $v)->orWhere('counter_cash_box_id', $v)))
            ->when($filters['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('voucher_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('voucher_date', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('number', 'like', "%$v%")
                ->orWhere('description', 'like', "%$v%")->orWhere('reference_no', 'like', "%$v%")))
            ->orderByDesc('voucher_date')->orderByDesc('id');

        return $query->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
    }

    /** One voucher with its lines, attachments and posted entries, for someone who may read its branch. */
    public function show(Voucher $voucher, User $user): array
    {
        $this->scope->assertBranch($user, 'finance.view', $voucher->branch_id);

        return $this->present($voucher);
    }

    /** API entry point: a draft (or, with `approve: true`, a posted voucher) within the actor's branches. */
    public function create(array $data, User $actor): Voucher
    {
        if (in_array($data['type'] ?? null, self::AUTOMATIC, true)) {
            throw ValidationException::withMessages(['type' => ['هذا النوع من السندات يُنشأ تلقائياً من مصدره.']]);
        }

        return DB::transaction(function () use ($data, $actor) {
            $voucher = $this->createInternal($data, $actor, checkScope: true);

            return ($data['approve'] ?? false) ? $this->approve($voucher, $actor) : $voucher;
        }, self::TX_ATTEMPTS);
    }

    /** Creates a draft without the API's type and branch restrictions (cash counts, payroll, later modules). */
    public function createInternal(array $data, User $actor, bool $checkScope = false): Voucher
    {
        return DB::transaction(function () use ($data, $actor, $checkScope) {
            $attributes = $this->normalize($data, $actor);
            if ($checkScope) {
                $this->assertReach($actor, 'finance.vouchers', $attributes);
            }
            $voucher = Voucher::create([...$attributes, 'status' => 'draft', 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->writeLines($voucher, $data['lines'] ?? []);
            $this->registry->for($voucher)->validate($voucher);
            $this->audit->log('finance.voucher_created', $voucher, null, $voucher->getAttributes(), $actor->id);

            return $voucher->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(Voucher $voucher, array $data, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $data, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless($voucher->status === 'draft', 409, 'تُعدَّل المسودات فقط؛ السند المعتمد يُصحَّح بالعكس.');
            abort_if((int) ($data['version'] ?? 0) !== $voucher->version, 409, 'تغيّر السند منذ فتحته؛ أعد تحميله.');
            $this->scope->assertBranch($actor, 'finance.vouchers', $voucher->branch_id);
            $data['type'] = $voucher->type;
            $attributes = $this->normalize([...$this->editable($voucher), ...$data], $actor);
            $this->assertReach($actor, 'finance.vouchers', $attributes);
            $old = $voucher->getAttributes();
            $voucher->update([...$attributes, 'version' => $voucher->version + 1, 'updated_by' => $actor->id]);
            if (array_key_exists('lines', $data)) {
                VoucherLine::query()->where('voucher_id', $voucher->id)->delete();
                $this->writeLines($voucher, $data['lines'] ?? []);
            }
            $this->registry->for($voucher)->validate($voucher);
            $this->audit->log('finance.voucher_updated', $voucher, $old, $voucher->getAttributes(), $actor->id);

            return ($data['approve'] ?? false) ? $this->approve($voucher, $actor) : $voucher->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function delete(Voucher $voucher, User $actor): void
    {
        DB::transaction(function () use ($voucher, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless($voucher->status === 'draft', 409, 'تُحذف المسودات فقط.');
            $this->scope->assertBranch($actor, 'finance.vouchers', $voucher->branch_id);
            $this->attachments->deleteAll($voucher);
            $this->audit->log('finance.voucher_deleted', $voucher, $voucher->getAttributes(), null, $actor->id);
            $voucher->delete();
        }, self::TX_ATTEMPTS);
    }

    /** Draft → submitted: waits for a finance decision (site-request style). */
    public function submit(Voucher $voucher, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless($voucher->status === 'draft', 409, 'يُرسل السند وهو مسودة فقط.');
            $voucher->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->log('finance.voucher_submitted', $voucher, ['status' => 'draft'], ['status' => 'submitted'], $actor->id);

            return $voucher->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function approve(Voucher $voucher, User $actor): Voucher
    {
        $this->scope->assertBranch($actor, 'finance.vouchers', $voucher->branch_id);
        $this->assertReach($actor, 'finance.vouchers', $voucher->getAttributes());

        return $this->posting->approve($voucher, $actor);
    }

    public function reject(Voucher $voucher, string $reason, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $reason, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless($voucher->status === 'submitted', 409, 'يُرفض السند المُرسل للاعتماد فقط.');
            $this->scope->assertBranch($actor, 'finance.vouchers', $voucher->branch_id);
            $voucher->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejected_reason' => $reason]);
            $this->audit->log('finance.voucher_rejected', $voucher, ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason], $actor->id);

            return $voucher->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function reverse(Voucher $voucher, string $date, string $reason, User $actor): Voucher
    {
        abort_if(in_array($voucher->type, ['payroll_accrual', 'payroll_payment'], true), 409, 'سندات الرواتب تُلغى من وحدة الرواتب.');
        $this->scope->assertBranch($actor, 'finance.reverse', $voucher->branch_id);

        return $this->posting->reverse($voucher, $date, $reason, $actor);
    }

    /** API shape: the voucher, a display number for drafts, lines, attachments, posted entries and warnings. */
    public function present(Voucher $voucher): array
    {
        $voucher->load(['lines', 'attachments', 'entry.lines', 'reversalEntry.lines']);

        return [...$voucher->toArray(), 'display_number' => $voucher->number ?? 'مسودة #'.$voucher->id, 'warnings' => $voucher->warnings];
    }

    /**
     * Payload → columns: currency codes resolved, amounts rounded to the currency's decimals, exchange rate and
     * counter amount derived from each other, branch defaulted from the box, the account's only branch or the head office.
     */
    private function normalize(array $data, User $actor): array
    {
        $type = $data['type'] ?? null;
        if (! in_array($type, Voucher::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['نوع السند غير معروف.']]);
        }
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));
        $attributes['purpose'] = in_array($type, ['receipt', 'payment'], true) ? ($data['purpose'] ?? null) : null;
        if (in_array($type, ['receipt', 'payment'], true) && ! in_array($attributes['purpose'], PosterRegistry::PURPOSES[$type], true)) {
            throw ValidationException::withMessages(['purpose' => ['اختر غرض السند.']]);
        }
        $attributes['voucher_date'] ??= now()->toDateString();
        foreach (['currency' => 'currency_id', 'counter_currency' => 'counter_currency_id', 'fee_currency' => 'fee_currency_id'] as $code => $column) {
            $attributes[$column] = $this->currencyId($data[$column] ?? null, $data[$code] ?? null, $column);
        }
        if ($type === 'transfer') {
            $attributes['counter_currency_id'] = $attributes['currency_id'];
        }
        $attributes['amount'] = $this->amount($data['amount'] ?? null, $attributes['currency_id']);
        $attributes['counter_amount'] = $this->amount($data['counter_amount'] ?? null, $attributes['counter_currency_id']);
        $attributes['fee_amount'] = $this->amount($data['fee_amount'] ?? null, $attributes['fee_currency_id'] ?? $attributes['currency_id']);
        if ($attributes['fee_amount'] !== null && $attributes['fee_currency_id'] === null) {
            $attributes['fee_currency_id'] = $attributes['currency_id'];
        }
        $attributes['advance_deduction'] = $this->amount($data['advance_deduction'] ?? null, $attributes['currency_id']);
        $attributes['rate'] = isset($data['rate']) && $data['rate'] !== '' ? Money::round(Money::of($data['rate']), Money::RATE_SCALE) : null;
        if ($type === 'exchange' && $attributes['amount'] !== null && Money::isPositive($attributes['amount'])) {
            if ($attributes['counter_amount'] === null && $attributes['rate'] !== null) {
                $attributes['counter_amount'] = $this->amount(Money::mul($attributes['amount'], $attributes['rate'], 8), $attributes['counter_currency_id']);
            }
            if ($attributes['counter_amount'] !== null) {
                $attributes['rate'] = Money::div($attributes['counter_amount'], $attributes['amount'], Money::RATE_SCALE);
            }
        }
        if ($type === 'transfer') {
            $attributes['counter_amount'] = $attributes['amount'];
        }
        $attributes['branch_id'] = $this->branchFor($data, $actor);

        return $attributes;
    }

    private function branchFor(array $data, User $actor): int
    {
        if (! empty($data['branch_id'])) {
            return (int) $data['branch_id'];
        }
        if (! empty($data['cash_box_id']) && ($box = CashBox::query()->find($data['cash_box_id']))) {
            return $box->branch_id;
        }
        $assigned = $this->scope->assignedBranches($actor->id);
        if (count($assigned) === 1) {
            return $assigned[0];
        }
        $head = $this->branches->headOffice();
        if ($head === null) {
            throw ValidationException::withMessages(['branch_id' => ['حدّد الفرع.']]);
        }

        return $head->id;
    }

    /** The voucher's branch and every box it touches must be within the actor's reach (R6). */
    private function assertReach(User $actor, string $permission, array $attributes): void
    {
        $this->scope->assertBranch($actor, $permission, (int) $attributes['branch_id']);
        $boxIds = array_filter([$attributes['cash_box_id'] ?? null, $attributes['counter_cash_box_id'] ?? null]);
        foreach (CashBox::query()->whereIn('id', $boxIds)->get() as $box) {
            $this->scope->assertBranch($actor, $permission, $box->branch_id);
        }
    }

    private function currencyId(mixed $id, mixed $code, string $field): ?int
    {
        if ($id !== null && $id !== '') {
            return (int) $id;
        }
        if ($code === null || $code === '') {
            return null;
        }
        $found = Currency::query()->where('code', strtoupper((string) $code))->value('id');
        if ($found === null) {
            throw ValidationException::withMessages([$field => ['العملة غير موجودة.']]);
        }

        return (int) $found;
    }

    private function amount(mixed $value, ?int $currencyId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decimals = $currencyId === null ? Money::SCALE : (int) (Currency::query()->whereKey($currencyId)->value('decimals') ?? Money::SCALE);

        return Money::round(Money::of(is_numeric($value) ? $value : (string) $value), $decimals);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Voucher $voucher, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $currencyId = $this->currencyId($line['currency_id'] ?? null, $line['currency'] ?? null, "lines.$i.currency_id") ?? $voucher->currency_id;
            if ($currencyId === null) {
                throw ValidationException::withMessages(["lines.$i.currency_id" => ['حدّد عملة السطر.']]);
            }
            $accountId = $line['account_id'] ?? null;
            if (! empty($line['cash_box_id'])) {
                $accountId = CashBox::query()->whereKey($line['cash_box_id'])->value('account_id')
                    ?? throw ValidationException::withMessages(["lines.$i.cash_box_id" => ['الصندوق غير موجود.']]);
            }
            VoucherLine::create([
                'voucher_id' => $voucher->id, 'line_no' => $i + 1, 'account_id' => $accountId,
                'expense_category_id' => $line['expense_category_id'] ?? null, 'project_id' => $line['project_id'] ?? null,
                'project_cost_category_id' => $line['project_cost_category_id'] ?? null, 'party_id' => $line['party_id'] ?? null,
                'asset_id' => $line['asset_id'] ?? null, 'employee_id' => $line['employee_id'] ?? null, 'currency_id' => $currencyId,
                'debit' => $this->amount($line['debit'] ?? '0', $currencyId) ?? '0', 'credit' => $this->amount($line['credit'] ?? '0', $currencyId) ?? '0',
                'memo' => $line['memo'] ?? null,
            ]);
        }
    }

    /** The draft's current values, so a partial update keeps what it does not send. */
    private function editable(Voucher $voucher): array
    {
        return [...array_intersect_key($voucher->getAttributes(), array_flip([...self::FIELDS, 'currency_id', 'counter_currency_id', 'fee_currency_id',
            'amount', 'counter_amount', 'fee_amount', 'rate', 'advance_deduction'])), 'meta' => $voucher->meta];
    }
}
