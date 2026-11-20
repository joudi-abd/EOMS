<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;

/**
 * Custody settlement with invoices (entry #20), one voucher line per invoice, in the custody's currency. A line with a
 * project debits project_costs (project, cost category — `asset_operation` when the line names an asset — and
 * party = the supplier); a line without a project debits its expense category's account (expense category, asset,
 * supplier). The total is credited to custody (party = holder, custody, the custody's project).
 * R16: the total never exceeds the custody's balance (the custody row is locked first); more spending is recorded as
 * an additional custody issue first. Site requests paid from custody arrive here through VoucherService::createInternal.
 */
class CustodySettlementPoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private CustodyService $custodies)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $custody = $this->custodies->lockForDrawing($voucher->custody_id);
        if ($voucher->currency_id !== null && $voucher->currency_id !== $custody->currency_id) {
            $this->fail('currency_id', 'تُسوّى العهدة بعملتها '.$this->code($custody->currency_id).'.');
        }
        if ($voucher->party_id !== null && $voucher->party_id !== $custody->holder_party_id) {
            $this->fail('party_id', 'جهة التعامل على السند يجب أن تكون صاحب العهدة؛ المورد يُحدَّد على كل سطر.');
        }
        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get();
        if ($lines->isEmpty()) {
            $this->fail('lines', 'أدخل فاتورة واحدة على الأقل.');
        }
        foreach ($lines->values() as $i => $line) {
            $this->debitDraft($line, $custody, "lines.$i");
        }
        $total = Money::sum($lines->map(fn (VoucherLine $l) => (string) $l->debit));
        $balance = $this->custodies->totals($custody)['balance'];
        if (Money::cmp($total, $balance) > 0) {
            $decimals = $this->decimals($custody->currency_id);
            $this->fail('lines', 'مجموع التسوية '.Money::format($total, $decimals).' يتجاوز رصيد العهدة '.Money::format($balance, $decimals).' '
                .$this->code($custody->currency_id).'. سجّل صرف عهدة إضافياً أولاً.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $custody = $this->custodies->lockForDrawing($voucher->custody_id);
        $drafts = [];
        $total = '0';
        foreach (VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get()->values() as $i => $line) {
            $drafts[] = $this->debitDraft($line, $custody, "lines.$i");
            $total = Money::add($total, (string) $line->debit);
        }
        $drafts[] = LineDraft::credit($this->roles->id('custody'), $custody->currency_id, $total,
            ['party_id' => $custody->holder_party_id, 'project_id' => $custody->project_id, 'custody_id' => $custody->id], $voucher->description ?: 'تسوية عهدة');
        if ($voucher->currency_id === null || $voucher->amount === null || $voucher->party_id === null) {
            $voucher->forceFill(['currency_id' => $custody->currency_id, 'amount' => $total, 'party_id' => $custody->holder_party_id])->saveQuietly();
        }

        return $drafts;
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

    /** The debit for one invoice line: project cost or expense, as in #8. */
    private function debitDraft(VoucherLine $line, Custody $custody, string $field): LineDraft
    {
        if ($line->currency_id !== $custody->currency_id) {
            $this->fail("$field.currency_id", 'سطور التسوية بعملة العهدة '.$this->code($custody->currency_id).'.');
        }
        if (! Money::isPositive((string) $line->debit) || ! Money::isZero((string) $line->credit)) {
            $this->fail("$field.debit", 'كل فاتورة في التسوية مبلغ مدين موجب.');
        }
        if ($line->account_id !== null) {
            $this->fail("$field.account_id", 'سطر التسوية يُوجَّه بالمشروع أو بتصنيف المصروف، لا بحساب مباشر.');
        }
        if ($line->party_id !== null) {
            $this->party($line->party_id, [], "$field.party_id");
        }
        $amount = (string) $line->debit;
        $memo = $line->memo ?? 'تسوية عهدة';

        if ($line->project_id !== null) {
            $category = $line->asset_id !== null
                ? ProjectCostCategory::query()->where('system_key', 'asset_operation')->first()
                : ($line->project_cost_category_id === null ? null : ProjectCostCategory::query()->find($line->project_cost_category_id));
            if ($category === null || ! $category->is_active) {
                $this->fail("$field.project_cost_category_id", 'حدّد تصنيف تكلفة المشروع.');
            }

            return LineDraft::debit($this->roles->id('project_costs'), $custody->currency_id, $amount, ['project_id' => $line->project_id,
                'project_cost_category_id' => $category->id, 'party_id' => $line->party_id, 'asset_id' => $line->asset_id,
                'expense_category_id' => $line->expense_category_id], $memo);
        }

        $category = $line->expense_category_id === null ? null : ExpenseCategory::query()->find($line->expense_category_id);
        if ($category === null || ! $category->is_active) {
            $this->fail("$field.expense_category_id", 'حدّد المشروع أو تصنيف المصروف.');
        }
        if ($category->account_id === null) {
            $this->fail("$field.expense_category_id", 'تصنيف المصروف "'.$category->name.'" غير مربوط بحساب.');
        }

        return LineDraft::debit($category->account_id, $custody->currency_id, $amount, ['expense_category_id' => $category->id,
            'party_id' => $line->party_id, 'asset_id' => $line->asset_id], $memo);
    }
}
