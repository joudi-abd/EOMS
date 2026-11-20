<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * Partner deposit, financing the company (#13): Dr box / Cr `partners_current` or `partners_capital` — the accountant
 * picks which through `account_id` — with the partner as party. The party must hold the partner role.
 */
class PartnerDepositPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->party($voucher->party_id, ['partner']);
        $this->target($voucher);
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;

        return [
            LineDraft::debit($box->account_id, $voucher->currency_id, $amount),
            LineDraft::credit($this->target($voucher), $voucher->currency_id, $amount, ['party_id' => $voucher->party_id, 'project_id' => $voucher->project_id],
                $voucher->description),
        ];
    }

    /** The partners' current account or the capital account, as chosen on the voucher. */
    private function target(Voucher $voucher): int
    {
        $allowed = [$this->roles->id('partners_current'), $this->roles->id('partners_capital')];
        if (! in_array($voucher->account_id, $allowed, true)) {
            $this->fail('account_id', 'اختر الحساب الجاري للشريك أو حساب رأس المال.');
        }

        return (int) $voucher->account_id;
    }
}
