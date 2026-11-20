<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * Salary accrual when a pay run is approved (#15), created by Payroll through PayrollFinanceService::accrue(), which
 * writes the voucher lines already resolved per employee: Dr salaries_expense / component expense accounts /
 * project_costs (project, loaded_payroll) for earnings; Cr employee_advances (installments), component accounts or
 * salaries_expense for deductions; Cr salaries_payable for the net. The lines post as they are; the ledger checks the
 * balance per currency and each account's own rules. No cash box moves on an accrual.
 */
class PayrollAccrualPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->get(['account_id']);
        if ($lines->isEmpty()) {
            $this->fail('lines', 'لا توجد رواتب للاستحقاق.');
        }
        if (CashBox::query()->whereIn('account_id', $lines->pluck('account_id')->filter())->exists()) {
            $this->fail('lines', 'استحقاق الرواتب لا يحرّك الصناديق.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        return VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get()
            ->map(fn (VoucherLine $l) => new LineDraft((int) $l->account_id, $l->currency_id, (string) $l->debit, (string) $l->credit,
                array_intersect_key($l->getAttributes(), array_flip(LineDraft::DIMENSIONS)), $l->memo))
            ->all();
    }
}
