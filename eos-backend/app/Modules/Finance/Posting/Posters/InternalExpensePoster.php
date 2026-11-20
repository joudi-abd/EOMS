<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Expenses\ExpenseRouting;

/**
 * Internal expense on a payment voucher (#8, single line): Dr the expense category's account (expense category, asset,
 * party) — or, with a project, Dr `project_costs` (project, cost category; `asset_operation` when an asset is set and no
 * cost category is given) — / Cr box.
 */
class InternalExpensePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private ExpenseRouting $routing)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        if ($voucher->party_id !== null) {
            $this->party($voucher->party_id);
        }
        $this->routing->debit($this->dims($voucher), $voucher->currency_id, (string) $voucher->amount, $voucher->description);
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;

        return [$this->routing->debit($this->dims($voucher), $voucher->currency_id, $amount, $voucher->description),
            LineDraft::credit($box->account_id, $voucher->currency_id, $amount)];
    }
}
