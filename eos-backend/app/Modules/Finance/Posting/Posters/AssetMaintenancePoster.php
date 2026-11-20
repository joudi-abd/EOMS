<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\AssetMaintenance;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Assets\AssetGuard;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Projects\Services\ProjectService;

/**
 * Asset maintenance (#23, D13): without a project Dr asset_maintenance (asset) / Cr box; with a project the cost goes
 * to the project as asset operation: Dr project_costs (project, cost category `asset_operation`, asset) / Cr box.
 * Posting records the maintenance on the asset card; reversal removes the record.
 */
class AssetMaintenancePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private AssetGuard $assets, private ProjectService $projects)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->assets->operable($voucher->asset_id);
        if (blank($voucher->description)) {
            $this->fail('description', 'اكتب وصف الصيانة.');
        }
        if ($voucher->project_id !== null) {
            if ($this->projects->find($voucher->project_id) === null) {
                $this->fail('project_id', 'المشروع غير موجود.');
            }
            if ($voucher->project_cost_category_id !== null && $voucher->project_cost_category_id !== $this->operationCategoryId()) {
                $this->fail('project_cost_category_id', 'صيانة الأصل على مشروع تُسجَّل بتصنيف "تشغيل أصول".');
            }
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $debit = $voucher->project_id === null
            ? LineDraft::debit($this->roles->id('asset_maintenance'), $voucher->currency_id, $amount, ['asset_id' => $voucher->asset_id], $voucher->description)
            : LineDraft::debit($this->roles->id('project_costs'), $voucher->currency_id, $amount, ['asset_id' => $voucher->asset_id,
                'project_id' => $voucher->project_id, 'project_cost_category_id' => $this->operationCategoryId()], $voucher->description);

        return [$debit, LineDraft::credit($box->account_id, $voucher->currency_id, $amount, [], $voucher->description)];
    }

    public function afterPost(Voucher $voucher): void
    {
        AssetMaintenance::create(['asset_id' => $voucher->asset_id, 'voucher_id' => $voucher->id, 'description' => $voucher->description,
            'date' => $voucher->voucher_date->toDateString(), 'project_id' => $voucher->project_id, 'created_by' => $voucher->approved_by,
            'updated_by' => $voucher->approved_by]);
    }

    public function afterReverse(Voucher $voucher): void
    {
        AssetMaintenance::query()->where('voucher_id', $voucher->id)->delete();
    }

    private function operationCategoryId(): int
    {
        $id = ProjectCostCategory::query()->where('system_key', 'asset_operation')->value('id');
        if ($id === null) {
            $this->fail('project_id', 'تصنيف تكلفة "تشغيل أصول" غير موجود.');
        }

        return (int) $id;
    }
}
