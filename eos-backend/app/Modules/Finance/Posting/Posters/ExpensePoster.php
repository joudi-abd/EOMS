<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Expenses\ExpenseRouting;
use App\Modules\Finance\Support\Money;
use Illuminate\Support\Collection;

/**
 * Multi-line expense voucher (#8) paid from one box in one currency. Each line's amount is its `debit`; per line:
 * with a project → Dr `project_costs` (project, cost category, or `asset_operation` when an asset is set without a
 * category), else Dr the expense category's account (expense category, asset); one Cr box for the total. Lines are in
 * the box currency only. The voucher's `amount` is kept equal to the lines' total.
 */
class ExpensePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private ExpenseRouting $routing)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        if ($voucher->currency_id === null) {
            $this->fail('currency_id', 'حدّد العملة.');
        }
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $lines = $this->rows($voucher);
        if ($lines->isEmpty()) {
            $this->fail('lines', 'أضف بنداً واحداً على الأقل.');
        }
        foreach ($lines as $i => $line) {
            $this->debitFor($voucher, $line, $i);
        }
        $total = Money::sum($lines->map(fn (VoucherLine $l) => (string) $l->debit));
        if ($voucher->amount === null || Money::cmp((string) $voucher->amount, $total) !== 0) {
            $voucher->forceFill(['amount' => $total])->saveQuietly();
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $lines = $this->rows($voucher);
        $debits = $lines->map(fn (VoucherLine $line, int $i) => $this->debitFor($voucher, $line, $i))->all();
        $total = Money::sum($lines->map(fn (VoucherLine $l) => (string) $l->debit));

        return [...$debits, LineDraft::credit($box->account_id, $voucher->currency_id, $total, [], $voucher->description)];
    }

    /** @return Collection<int, VoucherLine> */
    private function rows(Voucher $voucher): Collection
    {
        return VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get()->values();
    }

    private function debitFor(Voucher $voucher, VoucherLine $line, int $i): LineDraft
    {
        if ($line->currency_id !== $voucher->currency_id) {
            $this->fail("lines.$i.currency_id", 'بنود سند المصروف بعملة الصندوق المختارة فقط.');
        }
        if (! Money::isPositive((string) $line->debit) || ! Money::isZero((string) $line->credit)) {
            $this->fail("lines.$i.debit", 'مبلغ البند يجب أن يكون أكبر من صفر.');
        }
        if ($line->account_id !== null) {
            $this->fail("lines.$i.account_id", 'بند المصروف يُوجَّه بالتصنيف أو المشروع، لا بحساب مباشر.');
        }
        if ($line->party_id !== null) {
            $this->party($line->party_id, [], "lines.$i.party_id");
        }

        return $this->routing->debit($line->only(['expense_category_id', 'project_id', 'project_cost_category_id', 'asset_id', 'party_id']),
            $voucher->currency_id, (string) $line->debit, $line->memo ?? $voucher->description, "lines.$i.");
    }
}
