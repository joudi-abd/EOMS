<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Facades\Auth;

/**
 * Employee advance (D17, entry #14), as `advance_issue` or `payment.employee_advance`: Dr employee_advances
 * (employee, party) / Cr box. The employee must have a linked party; the currency must be a payroll currency
 * (salaries are paid in them, D20). `meta.installments_count` (≥ 1) equal monthly installments from
 * `meta.start_month` (YYYY-MM, not before the voucher's month) are created when it posts; reversing it cancels the
 * advance, refused once an installment was deducted from a salary.
 */
class EmployeeAdvancePoster extends AbstractPoster
{
    /** Upper bound on the schedule length (ten years of monthly installments). */
    public const MAX_INSTALLMENTS = 120;

    public function __construct(SystemAccounts $roles, private EmployeeAdvanceService $advances)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $amount = $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $currency = Currency::query()->find($voucher->currency_id);
        if ($currency === null || ! $currency->is_payroll_currency) {
            $codes = Currency::query()->where('is_payroll_currency', true)->where('is_active', true)->orderBy('code')->pluck('code')->implode('، ');
            $this->fail('currency_id', "سلفة الموظف تُصرف بإحدى عملات الرواتب: {$codes}.");
        }
        $employee = $voucher->employee_id === null ? null : $this->advances->employee($voucher->employee_id);
        if ($employee === null) {
            $this->fail('employee_id', 'اختر الموظف.');
        }
        if ($employee->status === 'terminated') {
            $this->fail('employee_id', 'لا تُصرف سلفة لموظف انتهت خدمته.');
        }
        $party = $this->advances->partyOf($employee->id);
        if ($party === null) {
            $this->fail('party_id', 'الموظف غير مرتبط بجهة تعامل؛ اربطه بجهة من شاشة جهات التعامل أولاً.');
        }
        if ($voucher->party_id !== null && $voucher->party_id !== $party->id) {
            $this->fail('party_id', 'جهة التعامل يجب أن تكون الجهة المرتبطة بالموظف.');
        }
        $this->party($party->id);

        $meta = $voucher->meta ?? [];
        $count = $meta['installments_count'] ?? null;
        if (filter_var($count, FILTER_VALIDATE_INT) === false || (int) $count < 1 || (int) $count > self::MAX_INSTALLMENTS) {
            $this->fail('meta.installments_count', 'عدد الأقساط عدد صحيح من 1 إلى '.self::MAX_INSTALLMENTS.'.');
        }
        $start = $meta['start_month'] ?? null;
        if (! is_string($start) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $start) !== 1) {
            $this->fail('meta.start_month', 'شهر بدء الخصم بصيغة YYYY-MM.');
        }
        if ($start < $voucher->voucher_date->format('Y-m')) {
            $this->fail('meta.start_month', 'شهر بدء الخصم لا يسبق شهر السند.');
        }
        $installments = $this->advances->split($amount, (int) $count, $this->decimals($voucher->currency_id));
        if (! Money::isPositive($installments[0])) {
            $this->fail('meta.installments_count', 'عدد الأقساط أكبر من أن يُقسم عليه المبلغ.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        if ($voucher->party_id === null) {
            $voucher->forceFill(['party_id' => $this->advances->partyOf($voucher->employee_id)?->id])->saveQuietly();
        }
        $amount = (string) $voucher->amount;

        return [
            LineDraft::debit($this->roles->id('employee_advances'), $voucher->currency_id, $amount,
                ['employee_id' => $voucher->employee_id, 'party_id' => $voucher->party_id], $voucher->description ?: 'سلفة موظف'),
            LineDraft::credit($box->account_id, $voucher->currency_id, $amount, [], $voucher->description ?: 'سلفة موظف'),
        ];
    }

    public function afterPost(Voucher $voucher): void
    {
        $this->advances->createFromVoucher($voucher, $voucher->approved_by);
    }

    public function afterReverse(Voucher $voucher): void
    {
        $this->advances->cancelFromVoucher($voucher, $voucher->reversed_by ?? Auth::id());
    }
}
