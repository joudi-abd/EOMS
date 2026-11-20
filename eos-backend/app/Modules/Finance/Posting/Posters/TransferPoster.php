<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/** Transfer between two boxes in one currency (#9): Dr box B / Cr box A; a fee leaves box A to transfer_fees. */
class TransferPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        if ($voucher->counter_cash_box_id === null || $voucher->counter_cash_box_id === $voucher->cash_box_id) {
            $this->fail('counter_cash_box_id', 'التحويل بين صندوقين مختلفين.');
        }
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->box($voucher->counter_cash_box_id, $voucher->currency_id, 'counter_cash_box_id');
        $this->feeLines($voucher, $this->box($voucher->cash_box_id, null));
    }

    public function lines(Voucher $voucher): array
    {
        $from = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $to = $this->box($voucher->counter_cash_box_id, $voucher->currency_id, 'counter_cash_box_id');
        $amount = (string) $voucher->amount;

        return [
            LineDraft::debit($to->account_id, $voucher->currency_id, $amount, [], 'من '.$from->name),
            LineDraft::credit($from->account_id, $voucher->currency_id, $amount, [], 'إلى '.$to->name),
            ...$this->feeLines($voucher, $from),
        ];
    }
}
