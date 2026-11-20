<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Modules\Settings\Services\SettingService;

/**
 * Currency exchange (#10): `amount` of X leaves box A, `counter_amount` of Y enters box B (A may equal B for a
 * multi-currency box). Two lines per currency through fx_position, each carrying the deal rate:
 * Dr fx_position [X] / Cr A [X]; Dr B [Y] / Cr fx_position [Y]. At the bulletin rate fx_position nets to 0 in USD.
 * R9: two different currencies, both amounts > 0. R10: a rate far from the bulletin only warns.
 */
class ExchangePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private ExchangeRateService $rates, private SettingService $settings)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->requireAmount($voucher, 'counter_amount', 'counter_currency_id');
        if ($voucher->currency_id === $voucher->counter_currency_id) {
            $this->fail('counter_currency_id', 'التصريف بين عملتين مختلفتين؛ استخدم التحويل لنفس العملة.');
        }
        if ($voucher->rate === null || ! Money::isPositive((string) $voucher->rate)) {
            $this->fail('rate', 'سعر التصريف يجب أن يكون أكبر من صفر.');
        }
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->box($voucher->counter_cash_box_id, $voucher->counter_currency_id, 'counter_cash_box_id');
        $this->feeLines($voucher, $this->box($voucher->cash_box_id, null));
    }

    public function lines(Voucher $voucher): array
    {
        $from = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $to = $this->box($voucher->counter_cash_box_id, $voucher->counter_currency_id, 'counter_cash_box_id');
        $fx = $this->roles->id('fx_position');
        $rate = (string) $voucher->rate;
        $amount = (string) $voucher->amount;
        $counter = (string) $voucher->counter_amount;

        return [
            LineDraft::debit($fx, $voucher->currency_id, $amount, [], 'تصريف إلى '.$this->code($voucher->counter_currency_id), $rate),
            LineDraft::credit($from->account_id, $voucher->currency_id, $amount, [], 'تصريف', $rate),
            LineDraft::debit($to->account_id, $voucher->counter_currency_id, $counter, [], 'تصريف', $rate),
            LineDraft::credit($fx, $voucher->counter_currency_id, $counter, [], 'تصريف من '.$this->code($voucher->currency_id), $rate),
            ...$this->feeLines($voucher, $from),
        ];
    }

    public function warnings(Voucher $voucher): array
    {
        if ($voucher->rate === null || $voucher->currency_id === null || $voucher->counter_currency_id === null) {
            return [];
        }
        $from = $this->code($voucher->currency_id);
        $to = $this->code($voucher->counter_currency_id);
        $bulletin = $this->rates->crossRateOnOrBefore($from, $to, $voucher->voucher_date->toDateString());
        if ($bulletin === null || ! Money::isPositive($bulletin)) {
            return ["لا يوجد سعر نشرة لـ {$from}/{$to} حتى تاريخ السند للمقارنة."];
        }
        $tolerance = Money::of((string) $this->settings->get('finance.exchange_rate_tolerance_pct', '5'));
        $deviation = Money::mul(Money::div(Money::abs(Money::sub((string) $voucher->rate, $bulletin)), $bulletin, 10), '100', 2);
        if (Money::cmp($deviation, $tolerance, 2) > 0) {
            return ['سعر التصريف '.Money::of((string) $voucher->rate).' يبتعد '.$deviation.'% عن سعر النشرة '.Money::of($bulletin).'.'];
        }

        return [];
    }
}
