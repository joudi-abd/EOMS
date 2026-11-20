<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Support\Money;

/**
 * Cash count difference (#28), created only when a count is approved. Each voucher line holds one currency's
 * difference on the box account: debit = overage (Dr box / Cr cash_over_short), credit = shortage
 * (Dr cash_over_short / Cr box).
 */
class CountAdjustmentPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        if (empty($voucher->meta['cash_count_id'])) {
            $this->fail('type', 'فرق الجرد يُرحَّل من جرد معتمد فقط.');
        }
        $this->box($voucher->cash_box_id, null);
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, null);
        $overShort = $this->roles->id('cash_over_short');
        $lines = [];
        foreach (VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get() as $line) {
            $difference = Money::sub((string) $line->debit, (string) $line->credit);
            $lines[] = LineDraft::signed($box->account_id, $line->currency_id, $difference, [], $line->memo);
            $lines[] = LineDraft::signed($overShort, $line->currency_id, Money::neg($difference), [], $line->memo ?? 'فرق جرد');
        }

        return array_values(array_filter($lines));
    }
}
