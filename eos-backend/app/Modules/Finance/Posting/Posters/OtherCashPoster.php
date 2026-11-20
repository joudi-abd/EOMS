<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * Receipt or payment of purpose "other": the box against an account finance chooses. The account may not be a box
 * or an account playing a system role (those move through their own vouchers), except the opening balances account.
 */
class OtherCashPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $account = $this->plainAccount($voucher->account_id);
        if ($this->roles->isRoleAccount($account->id, ['opening_balance_equity'])) {
            $this->fail('account_id', 'هذا الحساب مربوط بدور نظام ويتحرك عبر سنداته الخاصة؛ اختر حساباً آخر.');
        }
        if ($voucher->party_id !== null) {
            $this->party($voucher->party_id);
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $boxLine = $voucher->type === 'receipt'
            ? LineDraft::debit($box->account_id, $voucher->currency_id, $amount)
            : LineDraft::credit($box->account_id, $voucher->currency_id, $amount);
        $counter = $voucher->type === 'receipt'
            ? LineDraft::credit($voucher->account_id, $voucher->currency_id, $amount, $this->dims($voucher), $voucher->description)
            : LineDraft::debit($voucher->account_id, $voucher->currency_id, $amount, $this->dims($voucher), $voucher->description);

        return $voucher->type === 'receipt' ? [$boxLine, $counter] : [$counter, $boxLine];
    }
}
