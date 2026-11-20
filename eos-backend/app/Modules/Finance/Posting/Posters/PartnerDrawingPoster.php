<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Expenses\ExpenseRouting;

/**
 * Partner (manager) drawing (#11, #12). D15: the accountant classifies every drawing by hand.
 * - partner_personal (#11): Dr `partners_current` (party = partner) / Cr box.
 * - company_expense (#12): booked like an internal expense (#8) — expense category required, project optional — with
 *   the partner as party and the memo "بطلب المدير" / Cr box.
 */
class PartnerDrawingPoster extends AbstractPoster
{
    public const MANAGER_MEMO = 'بطلب المدير';

    public function __construct(SystemAccounts $roles, private ExpenseRouting $routing)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->party($voucher->party_id, ['partner']);
        if (! in_array($voucher->classification, ['partner_personal', 'company_expense'], true)) {
            $this->fail('classification', 'حدّد تصنيف السحب: مسحوبات شخصية أو مصروف شركة.');
        }
        if ($voucher->classification === 'company_expense' && $voucher->expense_category_id === null) {
            $this->fail('expense_category_id', 'اختر تصنيف المصروف.');
        }
        $this->debitLine($voucher);
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);

        return [$this->debitLine($voucher), LineDraft::credit($box->account_id, $voucher->currency_id, (string) $voucher->amount)];
    }

    private function debitLine(Voucher $voucher): LineDraft
    {
        $amount = (string) $voucher->amount;
        if ($voucher->classification === 'partner_personal') {
            return LineDraft::debit($this->roles->id('partners_current'), $voucher->currency_id, $amount,
                ['party_id' => $voucher->party_id, 'project_id' => $voucher->project_id], $voucher->description);
        }
        $memo = trim(self::MANAGER_MEMO.($voucher->description ? ' — '.$voucher->description : ''));

        return $this->routing->debit($this->dims($voucher), $voucher->currency_id, $amount, mb_substr($memo, 0, 500));
    }
}
