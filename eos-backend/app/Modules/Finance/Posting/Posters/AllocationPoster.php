<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Allocations\AllocationService;
use App\Modules\Finance\Support\Money;

/**
 * Allocation to projects (#17 payroll, #18 overhead), created by AllocationService with its lines already computed:
 * Dr project_costs (project, loaded_payroll / loaded_overhead) / Cr payroll_allocation_contra / overhead_allocation_contra.
 * Reversing the voucher marks the allocation reversed, so the month and branch can be allocated again.
 */
class AllocationPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $kind = $voucher->meta['kind'] ?? null;
        if (! in_array($kind, ['payroll', 'overhead'], true)) {
            $this->fail('meta.kind', 'نوع التوزيع غير معروف.');
        }
        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get();
        if ($lines->count() < 2) {
            $this->fail('lines', 'لا يوجد ما يُوزَّع.');
        }
        $costs = $this->roles->id('project_costs');
        $contra = $this->roles->id($kind === 'payroll' ? 'payroll_allocation_contra' : 'overhead_allocation_contra');
        $category = ProjectCostCategory::query()->where('system_key', $kind === 'payroll' ? 'loaded_payroll' : 'loaded_overhead')->value('id');
        foreach ($lines as $i => $line) {
            $debit = Money::isPositive((string) $line->debit);
            $valid = $debit
                ? $line->account_id === $costs && $line->project_id !== null && $line->project_cost_category_id === (int) $category
                : $line->account_id === $contra && Money::isPositive((string) $line->credit);
            if (! $valid) {
                $this->fail("lines.$i", 'سطر التوزيع يجب أن يكون مدين تكاليف مشروع بالتصنيف المحمّل أو دائن حساب المقابل.');
            }
        }
    }

    public function lines(Voucher $voucher): array
    {
        return VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get()
            ->map(fn (VoucherLine $l) => new LineDraft((int) $l->account_id, $l->currency_id, (string) $l->debit, (string) $l->credit,
                array_intersect_key($l->getAttributes(), array_flip(LineDraft::DIMENSIONS)), $l->memo))
            ->all();
    }

    public function afterReverse(Voucher $voucher): void
    {
        app(AllocationService::class)->markReversed($voucher, $voucher->reversed_by);
    }
}
