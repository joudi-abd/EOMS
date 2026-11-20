<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Support\Money;

/**
 * Salary payment (#16), created by Payroll through PayrollFinanceService. Voucher fields: `counter_currency_id` /
 * `counter_amount` = the contract currency and net salary, `currency_id` / `amount` = the paid currency and the
 * rounded amount that left the box, `rate` = contract → paid rate, `meta.converted` = net × rate before rounding.
 * Same currency: Dr salaries_payable net / Cr box paid / rounding difference on rounding_differences.
 * Other currency: Dr salaries_payable [C net] / Cr fx_position [C net]; Dr fx_position [P converted] / Cr box [P paid]
 * / rounding [P paid − converted].
 */
class PayrollPaymentPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->requireAmount($voucher, 'counter_amount', 'counter_currency_id');
        if ($voucher->employee_id === null) {
            $this->fail('employee_id', 'حدّد الموظف.');
        }
        $this->box($voucher->cash_box_id, $voucher->currency_id, 'box_id');
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id, 'box_id');
        $contract = (int) $voucher->counter_currency_id;
        $paid = (int) $voucher->currency_id;
        $employee = ['employee_id' => $voucher->employee_id];
        $net = Money::round((string) $voucher->counter_amount, $this->decimals($contract));
        $paidAmount = Money::round((string) $voucher->amount, $this->decimals($paid));
        $payable = $this->roles->id('salaries_payable');
        $rounding = fn (string $difference) => LineDraft::signed($this->roles->id('rounding_differences'), $paid, $difference, $employee, 'فرق تقريب الراتب');

        if ($contract === $paid) {
            return array_values(array_filter([
                LineDraft::debit($payable, $contract, $net, $employee, $voucher->description),
                $rounding(Money::sub($paidAmount, $net)),
                LineDraft::credit($box->account_id, $paid, $paidAmount, [], $voucher->description),
            ]));
        }
        $fx = $this->roles->id('fx_position');
        $rate = $voucher->rate === null ? null : (string) $voucher->rate;
        $converted = Money::round((string) ($voucher->meta['converted'] ?? $voucher->amount), $this->decimals($paid));

        return array_values(array_filter([
            LineDraft::debit($payable, $contract, $net, $employee, $voucher->description, $rate),
            LineDraft::credit($fx, $contract, $net, [], 'صرف راتب بعملة أخرى', $rate),
            LineDraft::debit($fx, $paid, $converted, [], 'صرف راتب بعملة أخرى', $rate),
            $rounding(Money::sub($paidAmount, $converted)),
            LineDraft::credit($box->account_id, $paid, $paidAmount, [], $voucher->description, $rate),
        ]));
    }
}
