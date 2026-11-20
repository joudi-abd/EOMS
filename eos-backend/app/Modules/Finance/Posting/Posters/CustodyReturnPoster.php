<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;

/**
 * Return of what is left of a custody (entry #21): Dr box / Cr custody (party = holder, custody, project). The amount
 * is in the custody's currency and never above its balance; a zero balance closes the custody.
 */
class CustodyReturnPoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private CustodyService $custodies)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $amount = $this->requireAmount($voucher);
        $custody = $this->custodies->lockForDrawing($voucher->custody_id);
        if ($voucher->currency_id !== $custody->currency_id) {
            $this->fail('currency_id', 'رد العهدة بعملتها '.$this->code($custody->currency_id).'.');
        }
        if ($voucher->party_id !== null && $voucher->party_id !== $custody->holder_party_id) {
            $this->fail('party_id', 'جهة التعامل يجب أن تكون صاحب العهدة.');
        }
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $balance = $this->custodies->totals($custody)['balance'];
        if (Money::cmp($amount, $balance) > 0) {
            $this->fail('amount', 'المبلغ المردود أكبر من رصيد العهدة ('.Money::format($balance, $this->decimals($custody->currency_id)).' '
                .$this->code($custody->currency_id).').');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $custody = $this->custodies->lockForDrawing($voucher->custody_id);
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $memo = $voucher->description ?: 'رد عهدة';
        if ($voucher->party_id === null) {
            $voucher->forceFill(['party_id' => $custody->holder_party_id])->saveQuietly();
        }

        return [
            LineDraft::debit($box->account_id, $voucher->currency_id, $amount, [], $memo),
            LineDraft::credit($this->roles->id('custody'), $voucher->currency_id, $amount,
                ['party_id' => $custody->holder_party_id, 'project_id' => $custody->project_id, 'custody_id' => $custody->id], $memo),
        ];
    }

    public function afterPost(Voucher $voucher): void
    {
        $this->custodies->refresh($voucher->custody_id, $voucher->voucher_date->toDateString(), $voucher->approved_by);
    }

    public function afterReverse(Voucher $voucher): void
    {
        $date = JournalEntry::query()->whereKey($voucher->reversal_entry_id)->value('entry_date');
        $this->custodies->refresh($voucher->custody_id, $date ? substr((string) $date, 0, 10) : now()->toDateString(), $voucher->reversed_by);
    }
}
