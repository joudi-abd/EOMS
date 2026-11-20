<?php

namespace App\Modules\Finance\Services\Expenses;

use App\Modules\Finance\Models\Attachment;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\SiteRequest;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\AttachmentService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Services\FinanceNotifier;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\BranchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Supervisor site expense requests (R24, R25, spec §9.5–9.6). A supervisor (`finance.site_requests`; own = the projects
 * they supervise) submits: project, cost category, amount, currency, optional supplier, description, payment source
 * (custody | own_money) and the invoice image (mandatory). Finance (`finance.vouchers`) approves or rejects with a
 * reason; the requester is notified either way. Approval posts:
 * - custody: a `custody_settlement` voucher (#20) on the holder's open custody for the project and currency (else
 *   their general custody in that currency), one line for the project cost; the invoice is copied to the voucher.
 * - own_money: a `manual_journal` voucher — Dr `project_costs` (project, category, supplier) / Cr `other_payables`
 *   (party = the supervisor): the company owes the supervisor (R25).
 * Supervisors never approve (spec §14 item 11): deciding needs `finance.vouchers`.
 */
class SiteRequestService
{
    private const TX_ATTEMPTS = 3;

    public const SOURCES = ['custody', 'own_money'];

    public function __construct(private FinanceScope $scope, private VoucherService $vouchers, private AttachmentService $attachments,
        private SystemAccounts $roles, private ProjectService $projects, private BranchService $branches, private EmployeeDirectory $directory,
        private FinanceNotifier $notifier, private AuditLogger $audit) {}

    /** Requests the caller submitted ("طلباتي"). */
    public function mine(User $user, array $filters): LengthAwarePaginator
    {
        return $this->filtered(SiteRequest::query()->where('requested_by', $user->id), $filters);
    }

    /** Finance inbox (spec §9.5): requests in the caller's branches for `finance.vouchers`. */
    public function inbox(User $user, array $filters): LengthAwarePaginator
    {
        return $this->filtered($this->scope->apply(SiteRequest::query(), $user, 'finance.vouchers'), $filters);
    }

    /** The requester, or someone who decides requests of that branch, may open a request. */
    public function assertVisible(SiteRequest $request, User $user): void
    {
        abort_unless($request->requested_by === $user->id || $this->scope->allows($user, 'finance.vouchers', $request->branch_id),
            403, 'لا تملك صلاحية على هذا الطلب.');
    }

    /** R24: a submitted request with its invoice image. */
    public function submit(array $data, UploadedFile $invoice, User $actor): SiteRequest
    {
        return DB::transaction(function () use ($data, $invoice, $actor) {
            $projectId = (int) $data['project_id'];
            $reach = $this->scope->projectIds($actor, 'finance.site_requests');
            abort_if($reach !== null && ! in_array($projectId, $reach, true), 403, 'لا تشرف على هذا المشروع.');
            $project = $this->projects->find($projectId) ?? throw ValidationException::withMessages(['project_id' => ['المشروع غير موجود.']]);
            if ($project->status === 'closed') {
                throw ValidationException::withMessages(['project_id' => ['المشروع مغلق ولا يقبل حركات.']]);
            }
            $category = ProjectCostCategory::query()->find($data['project_cost_category_id']);
            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages(['project_cost_category_id' => ['تصنيف التكلفة غير موجود أو غير فعّال.']]);
            }
            $currency = $this->currency($data);
            $amount = Money::round(Money::of((string) $data['amount']), (int) $currency->decimals);
            if (! Money::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
            }
            $supplierId = $data['supplier_party_id'] ?? null;
            if ($supplierId !== null && ! Party::query()->whereKey($supplierId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['supplier_party_id' => ['المورد غير موجود أو غير فعّال.']]);
            }
            $holder = $this->holderParty($actor);
            if ($data['payment_source'] === 'custody' && $this->custodyFor($holder->id, $projectId, $currency->id) === null) {
                throw ValidationException::withMessages(['payment_source' => ['لا توجد لديك عهدة مفتوحة بعملة '.$currency->code.'؛ اختر "دفعته من مالي".']]);
            }
            $branchId = $project->branch_id ?? $this->branches->headOffice()?->id
                ?? throw ValidationException::withMessages(['project_id' => ['حدّد فرع المشروع أولاً.']]);
            $request = SiteRequest::create(['project_id' => $projectId, 'project_cost_category_id' => $category->id, 'amount' => $amount,
                'currency_id' => $currency->id, 'supplier_party_id' => $supplierId, 'description' => $data['description'],
                'payment_source' => $data['payment_source'], 'requested_by' => $actor->id, 'holder_party_id' => $holder->id, 'status' => 'submitted',
                'branch_id' => $branchId, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->attachments->store($request, $invoice, $actor);
            $this->audit->log('finance.site_request_submitted', $request, null, $request->getAttributes(), $actor->id);
            $this->notifier->toPermission('finance.vouchers', 'finance.site_request_submitted',
                "طلب مصروف موقع جديد من {$holder->name}: ".Money::format($amount, (int) $currency->decimals)." {$currency->code} — {$project->name}.",
                ['site_request_id' => $request->id, 'project_id' => $projectId], $actor->id);

            return $request->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** R25: posts the request (custody settlement or a debt to the supervisor) and marks it approved. */
    public function approve(SiteRequest $request, User $actor, ?string $date = null): SiteRequest
    {
        return DB::transaction(function () use ($request, $actor, $date) {
            $request = SiteRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->scope->assertBranch($actor, 'finance.vouchers', $request->branch_id);
            abort_unless($request->status === 'submitted', 409, 'الطلب ليس بانتظار القرار.');
            $date ??= now()->toDateString();
            $line = ['project_id' => $request->project_id, 'project_cost_category_id' => $request->project_cost_category_id,
                'party_id' => $request->supplier_party_id, 'currency_id' => $request->currency_id, 'debit' => (string) $request->amount,
                'memo' => $request->description];
            $common = ['voucher_date' => $date, 'currency_id' => $request->currency_id, 'project_id' => $request->project_id,
                'description' => mb_substr('طلب مصروف موقع #'.$request->id.' — '.$request->description, 0, 500), 'meta' => ['site_request_id' => $request->id]];
            $custodyId = null;
            if ($request->payment_source === 'custody') {
                $custody = $this->custodyFor($request->holder_party_id, $request->project_id, $request->currency_id, lock: true)
                    ?? throw ValidationException::withMessages(['payment_source' => ['لا توجد عهدة مفتوحة لصاحب الطلب بهذه العملة؛ ارفض الطلب أو اصرف عهدة أولاً.']]);
                $custodyId = $custody->id;
                $voucher = $this->vouchers->createInternal([...$common, 'type' => 'custody_settlement', 'branch_id' => $custody->branch_id,
                    'custody_id' => $custody->id, 'party_id' => $request->holder_party_id, 'amount' => (string) $request->amount, 'lines' => [$line]], $actor);
            } else {
                $voucher = $this->vouchers->createInternal([...$common, 'type' => 'manual_journal', 'branch_id' => $request->branch_id, 'lines' => [
                    [...$line, 'account_id' => $this->roles->id('project_costs')],
                    ['account_id' => $this->roles->id('other_payables'), 'party_id' => $request->holder_party_id, 'currency_id' => $request->currency_id,
                        'credit' => (string) $request->amount, 'memo' => 'دفعه المشرف من ماله — طلب #'.$request->id],
                ]], $actor);
            }
            $this->copyAttachments($request, $voucher, $actor);
            $voucher = $this->vouchers->approve($voucher, $actor);
            $old = $request->getAttributes();
            $request->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'voucher_id' => $voucher->id,
                'custody_id' => $custodyId, 'updated_by' => $actor->id]);
            $this->audit->log('finance.site_request_approved', $request, $old, $request->getAttributes(), $actor->id);
            $this->notifier->toUser($request->requested_by, 'finance.site_request_approved',
                'اعتُمد طلب مصروف الموقع #'.$request->id.' برقم السند '.$voucher->number.'.', ['site_request_id' => $request->id, 'voucher_id' => $voucher->id]);

            return $request->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** R25: rejected with a reason; the requester is notified. */
    public function reject(SiteRequest $request, string $reason, User $actor): SiteRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor) {
            $request = SiteRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->scope->assertBranch($actor, 'finance.vouchers', $request->branch_id);
            abort_unless($request->status === 'submitted', 409, 'الطلب ليس بانتظار القرار.');
            $old = $request->getAttributes();
            $request->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'rejected_reason' => $reason, 'updated_by' => $actor->id]);
            $this->audit->log('finance.site_request_rejected', $request, $old, $request->getAttributes(), $actor->id);
            $this->notifier->toUser($request->requested_by, 'finance.site_request_rejected',
                'رُفض طلب مصروف الموقع #'.$request->id.': '.$reason, ['site_request_id' => $request->id]);

            return $request->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** API shape: the request with names, its attachments and the posted voucher's number. */
    public function present(SiteRequest $request): array
    {
        $request->load('attachments');
        $parties = Party::query()->whereIn('id', array_filter([$request->holder_party_id, $request->supplier_party_id]))->pluck('name', 'id');

        return [...$request->toArray(), 'project_name' => $this->projects->find($request->project_id)?->name,
            'cost_category_name' => ProjectCostCategory::query()->whereKey($request->project_cost_category_id)->value('name'),
            'currency_code' => Currency::query()->whereKey($request->currency_id)->value('code'),
            'holder_name' => $parties->get($request->holder_party_id), 'supplier_name' => $parties->get($request->supplier_party_id),
            'voucher_number' => $request->voucher_id === null ? null : Voucher::query()->whereKey($request->voucher_id)->value('number')];
    }

    /** The requester's party: the party linked to the HR employee of the account. */
    public function holderParty(User $user): Party
    {
        $employeeId = $this->directory->employeeIdForUser($user->id);
        $party = $employeeId === null ? null : Party::query()->where('employee_id', $employeeId)->first();
        if ($party === null) {
            throw ValidationException::withMessages(['requested_by' => ['حسابك غير مرتبط بجهة تعامل؛ اطلب من المالية ربط ملفك الوظيفي بجهة تعامل.']]);
        }
        if (! $party->is_active) {
            throw ValidationException::withMessages(['requested_by' => ['جهة التعامل المرتبطة بحسابك غير فعّالة؛ راجع المالية.']]);
        }

        return $party;
    }

    /** The holder's open custody for the project and currency, else their open general custody in that currency. */
    private function custodyFor(int $holderPartyId, int $projectId, int $currencyId, bool $lock = false): ?Custody
    {
        $query = fn () => Custody::query()->where('holder_party_id', $holderPartyId)->where('currency_id', $currencyId)->where('status', 'open')
            ->when($lock, fn (Builder $q) => $q->lockForUpdate())->orderBy('id');

        return $query()->where('project_id', $projectId)->first() ?? $query()->whereNull('project_id')->first();
    }

    /** @param  array{currency_id?: int|null, currency?: string|null}  $data */
    private function currency(array $data): Currency
    {
        $currency = ! empty($data['currency_id']) ? Currency::query()->find($data['currency_id'])
            : Currency::query()->where('code', strtoupper((string) ($data['currency'] ?? '')))->first();
        if ($currency === null || ! $currency->is_active) {
            throw ValidationException::withMessages(['currency_id' => ['العملة غير موجودة أو غير فعّالة.']]);
        }

        return $currency;
    }

    /** The invoice image travels with the posted voucher (copied, so each record owns its file). */
    private function copyAttachments(SiteRequest $request, Voucher $voucher, User $actor): void
    {
        $disk = Storage::disk('local');
        foreach (Attachment::query()->where('attachable_type', $request->getMorphClass())->where('attachable_id', $request->id)->orderBy('id')->get() as $file) {
            $target = 'finance/'.$voucher->getMorphClass().'/'.$voucher->id.'/'.basename($file->path);
            if ($disk->exists($file->path)) {
                $disk->copy($file->path, $target);
            }
            Attachment::create(['attachable_type' => $voucher->getMorphClass(), 'attachable_id' => $voucher->id, 'path' => $target,
                'original_name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size, 'uploaded_by' => $actor->id]);
        }
    }

    private function filtered(Builder $query, array $filters): LengthAwarePaginator
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['project_id'] ?? null, fn (Builder $q, $v) => $q->where('project_id', $v))
            ->when($filters['payment_source'] ?? null, fn (Builder $q, $v) => $q->where('payment_source', $v))
            ->when($filters['requested_by'] ?? null, fn (Builder $q, $v) => $q->where('requested_by', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByRaw("case when status = 'submitted' then 0 else 1 end")->orderByDesc('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)))
            ->through(fn (SiteRequest $r) => $this->present($r));
    }
}
