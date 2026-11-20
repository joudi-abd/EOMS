<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * Payment to a supplier (#3). Without a project it settles the supplier's payable (Dr payables_suppliers). With a
 * project it is a direct project cost (Dr project_costs with project, cost category and supplier).
 */
class SupplierPaymentPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->party($voucher->party_id, ['supplier']);
        if ($voucher->project_id !== null) {
            $category = $voucher->project_cost_category_id === null ? null : ProjectCostCategory::query()->find($voucher->project_cost_category_id);
            if ($category === null || ! $category->is_active) {
                $this->fail('project_cost_category_id', 'حدّد تصنيف تكلفة المشروع.');
            }
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $debit = $voucher->project_id === null
            ? LineDraft::debit($this->roles->id('payables_suppliers'), $voucher->currency_id, $amount, ['party_id' => $voucher->party_id], $voucher->description)
            : LineDraft::debit($this->roles->id('project_costs'), $voucher->currency_id, $amount, ['party_id' => $voucher->party_id,
                'project_id' => $voucher->project_id, 'project_cost_category_id' => $voucher->project_cost_category_id], $voucher->description);

        return [$debit, LineDraft::credit($box->account_id, $voucher->currency_id, $amount)];
    }
}
