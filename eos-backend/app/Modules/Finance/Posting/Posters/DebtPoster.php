<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * General debts (#26, #27). we_owe: a receipt borrows (Dr box / Cr other_payables) and a payment repays.
 * owed_to_us: a payment lends (Dr other_receivables / Cr box) and a receipt collects.
 */
class DebtPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        if (! in_array($voucher->debt_direction, ['we_owe', 'owed_to_us'], true)) {
            $this->fail('debt_direction', 'حدّد اتجاه الدين: علينا أو لنا.');
        }
        $this->party($voucher->party_id);
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $account = $this->roles->id($voucher->debt_direction === 'we_owe' ? 'other_payables' : 'other_receivables');
        $amount = (string) $voucher->amount;
        $dims = ['party_id' => $voucher->party_id, 'project_id' => $voucher->project_id];
        if ($voucher->type === 'receipt') {
            return [LineDraft::debit($box->account_id, $voucher->currency_id, $amount), LineDraft::credit($account, $voucher->currency_id, $amount, $dims, $voucher->description)];
        }

        return [LineDraft::debit($account, $voucher->currency_id, $amount, $dims, $voucher->description), LineDraft::credit($box->account_id, $voucher->currency_id, $amount)];
    }
}
