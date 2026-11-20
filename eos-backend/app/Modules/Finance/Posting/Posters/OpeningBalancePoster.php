<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;

/**
 * Opening balances (#29, spec §12): the lines as entered (boxes, receivables, custody, ...) and, per currency, one
 * counter line on opening_balance_equity equal to minus the others. This is the one place the system adds the
 * balancing line itself. Lines may carry a custody (an open custody from the setup wizard): its cached totals are
 * refreshed after posting and after reversal; reversing also cancels the employee advances opened by the voucher.
 */
class OpeningBalancePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private CustodyService $custodies, private EmployeeAdvanceService $advances)
    {
        parent::__construct($roles);
    }

    public function afterPost(Voucher $voucher): void
    {
        foreach ($this->custodyIds($voucher) as $custodyId) {
            $this->custodies->refresh($custodyId, $voucher->voucher_date->toDateString(), $voucher->approved_by);
        }
    }

    public function afterReverse(Voucher $voucher): void
    {
        $date = JournalEntry::query()->whereKey($voucher->reversal_entry_id)->value('entry_date');
        foreach ($this->custodyIds($voucher) as $custodyId) {
            $this->custodies->refresh($custodyId, $date ? substr((string) $date, 0, 10) : now()->toDateString(), $voucher->reversed_by);
        }
        $this->advances->cancelFromVoucher($voucher, $voucher->reversed_by);
    }

    public function validate(Voucher $voucher): void
    {
        $lines = VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get();
        if ($lines->isEmpty()) {
            $this->fail('lines', 'أدخل سطراً واحداً على الأقل.');
        }
        foreach ($lines as $i => $line) {
            $account = $line->account_id === null ? null : Account::query()->find($line->account_id);
            if ($account === null || ! $account->is_active || ! $account->is_postable) {
                $this->fail("lines.$i.account_id", 'اختر حساباً فعّالاً يقبل القيود.');
            }
            if (Money::isPositive((string) $line->debit) === Money::isPositive((string) $line->credit)) {
                $this->fail("lines.$i", 'كل سطر مدين أو دائن بمبلغ موجب.');
            }
        }
    }

    public function lines(Voucher $voucher): array
    {
        $equity = $this->roles->id('opening_balance_equity');
        $lines = [];
        $net = [];
        foreach (VoucherLine::query()->where('voucher_id', $voucher->id)->orderBy('line_no')->get() as $line) {
            $lines[] = new LineDraft((int) $line->account_id, $line->currency_id, (string) $line->debit, (string) $line->credit,
                array_intersect_key($line->getAttributes(), array_flip(LineDraft::DIMENSIONS)), $line->memo ?? 'رصيد افتتاحي');
            $net[$line->currency_id] = Money::add($net[$line->currency_id] ?? '0', Money::sub((string) $line->debit, (string) $line->credit));
        }
        ksort($net);
        foreach ($net as $currencyId => $amount) {
            $counter = LineDraft::signed($equity, (int) $currencyId, Money::neg($amount), [], 'مقابل الأرصدة الافتتاحية');
            if ($counter !== null) {
                $lines[] = $counter;
            }
        }

        return $lines;
    }

    /** @return list<int> */
    private function custodyIds(Voucher $voucher): array
    {
        return VoucherLine::query()->where('voucher_id', $voucher->id)->whereNotNull('custody_id')->distinct()->pluck('custody_id')
            ->map(fn ($id) => (int) $id)->all();
    }
}
