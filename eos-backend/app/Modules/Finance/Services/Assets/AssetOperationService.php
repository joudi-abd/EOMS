<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetCustodyHistory;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operations on an asset in service (D22, spec §9.7): custody transfer between employees (no entry), maintenance
 * (#23), the in-service ↔ under-maintenance toggle, and disposal by sale, scrapping or loss (#24, #25). Each voucher
 * is created through VoucherService::createInternal and posted at once unless `approve` is false; the posters write
 * the maintenance and disposal records when the voucher posts and remove them when it is reversed.
 */
class AssetOperationService
{
    private const TX_ATTEMPTS = 3;

    public const DISPOSAL_TYPES = ['sale', 'scrap', 'loss'];

    public function __construct(private AssetGuard $guard, private AssetHolders $holders, private VoucherService $vouchers,
        private PostingService $posting, private FinanceScope $scope, private AuditLogger $audit) {}

    /** Moves the asset to another employee: a custody history row from the current holder, no journal entry. */
    public function transfer(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $asset = $this->guard->operable($asset->id);
            $to = $this->holders->assertAssignable((int) $data['to_employee_id'], 'to_employee_id');
            if ($to->id === $asset->current_holder_employee_id) {
                throw ValidationException::withMessages(['to_employee_id' => ['الأصل بعهدة هذا الموظف أصلاً.']]);
            }
            $from = $asset->current_holder_employee_id;
            $history = AssetCustodyHistory::create(['asset_id' => $asset->id, 'from_employee_id' => $from, 'to_employee_id' => $to->id,
                'transferred_at' => $data['date'], 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id]);
            $asset->update(['current_holder_employee_id' => $to->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.asset_transferred', $asset, ['current_holder_employee_id' => $from],
                ['current_holder_employee_id' => $to->id, 'date' => $data['date'], 'history_id' => $history->id], $actor->id);

            return $asset->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** in_service ↔ under_maintenance (no entry). */
    public function setStatus(Asset $asset, string $status, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $status, $actor) {
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $asset = $this->guard->operable($asset->id);
            if (! in_array($status, AssetGuard::ACTIVE, true)) {
                throw ValidationException::withMessages(['status' => ['الحالة تكون "في الخدمة" أو "قيد الصيانة"؛ الاستبعاد يتم بسند.']]);
            }
            if ($asset->status !== $status) {
                $old = $asset->status;
                $asset->update(['status' => $status, 'updated_by' => $actor->id]);
                $this->audit->log('finance.asset_status_changed', $asset, ['status' => $old], ['status' => $status], $actor->id);
            }

            return $asset->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Maintenance paid from a box (#23): asset_maintenance voucher. Without a project Dr asset_maintenance (asset);
     * with a project Dr project_costs (project, cost category "asset operation", asset). Cr the box.
     */
    public function maintain(Asset $asset, array $data, User $actor): Voucher
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $asset = $this->guard->operable($asset->id);
            $box = $this->reachableBox($asset, $data, $actor);
            $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
            $voucher = $this->vouchers->createInternal(['type' => 'asset_maintenance', 'voucher_date' => $data['date'], 'branch_id' => $asset->branch_id,
                'cash_box_id' => $box->id, 'currency_id' => $data['currency_id'] ?? null, 'currency' => $data['currency'] ?? null, 'amount' => $data['amount'],
                'asset_id' => $asset->id, 'project_id' => $projectId,
                'project_cost_category_id' => $projectId === null ? null : $this->assetOperationCategoryId(),
                'reference_no' => $data['reference_no'] ?? null,
                'description' => Str::limit($data['description'], 495)], $actor);

            return ($data['approve'] ?? true) ? $this->posting->approve($voucher, $actor) : $voucher;
        }, self::TX_ATTEMPTS);
    }

    /**
     * Sale, scrapping or loss (#24, #25): asset_disposal voucher. A sale is priced in the asset's currency (else 422)
     * and received in a box; the asset leaves the books at its cost (no depreciation, D22).
     */
    public function dispose(Asset $asset, array $data, User $actor): Voucher
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $asset = $this->guard->operable($asset->id);
            $type = $data['type'];
            $payload = ['type' => 'asset_disposal', 'voucher_date' => $data['date'], 'branch_id' => $asset->branch_id, 'asset_id' => $asset->id,
                'currency_id' => $asset->currency_id, 'reference_no' => $data['reference_no'] ?? null,
                'meta' => ['disposal_type' => $type, 'notes' => $data['notes'] ?? null],
                'description' => Str::limit($data['description'] ?? $this->disposalLabel($type).' '.$asset->code.' — '.$asset->name, 495)];
            if ($type === 'sale') {
                $currency = $data['currency'] ?? null;
                $currencyId = $data['currency_id'] ?? ($currency === null ? null : Currency::query()->where('code', strtoupper((string) $currency))->value('id'));
                if ($currencyId !== null && (int) $currencyId !== $asset->currency_id) {
                    throw ValidationException::withMessages(['currency' => ['سعر البيع يُسجَّل بعملة الأصل نفسها.']]);
                }
                $box = $this->reachableBox($asset, $data, $actor);
                $payload += ['cash_box_id' => $box->id, 'amount' => $data['sale_amount'] ?? null];
            }
            $voucher = $this->vouchers->createInternal($payload, $actor);

            return ($data['approve'] ?? true) ? $this->posting->approve($voucher, $actor) : $voucher;
        }, self::TX_ATTEMPTS);
    }

    public function disposalLabel(string $type): string
    {
        return ['sale' => 'بيع أصل', 'scrap' => 'إتلاف أصل', 'loss' => 'فقدان أصل'][$type] ?? 'استبعاد أصل';
    }

    /** The asset's branch and the box's branch must both be within the actor's asset reach (R6). */
    private function reachableBox(Asset $asset, array $data, User $actor): CashBox
    {
        $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
        $box = CashBox::query()->find($data['cash_box_id'] ?? null)
            ?? throw ValidationException::withMessages(['cash_box_id' => ['اختر الصندوق.']]);
        $this->scope->assertBranch($actor, 'finance.assets', $box->branch_id);

        return $box;
    }

    private function assetOperationCategoryId(): int
    {
        return (int) (ProjectCostCategory::query()->where('system_key', 'asset_operation')->value('id')
            ?? throw ValidationException::withMessages(['project_id' => ['تصنيف تكلفة "تشغيل أصول" غير موجود.']]));
    }
}
