<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Support\Money;

/** A manual journal: the lines as entered, balanced per currency by the ledger (R2). Box accounts are refused. */
class ManualJournalPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get();
        if ($lines->count() < 2) {
            $this->fail('lines', 'القيد يحتاج سطرين على الأقل.');
        }
        foreach ($lines as $i => $line) {
            $this->plainAccount($line->account_id, "lines.$i.account_id");
            if (Money::isPositive((string) $line->debit) === Money::isPositive((string) $line->credit)) {
                $this->fail("lines.$i", 'كل سطر مدين أو دائن بمبلغ موجب.');
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
}
