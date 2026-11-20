<?php

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Illuminate\Validation\ValidationException;

/** Shared checks and helpers for posters; hooks default to nothing. */
abstract class AbstractPoster implements Poster
{
    public function __construct(protected SystemAccounts $roles) {}

    public function afterPost(Voucher $voucher): void {}

    public function afterReverse(Voucher $voucher): void {}

    public function warnings(Voucher $voucher): array
    {
        return [];
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** An active box (R4) with the currency enabled (R7). */
    protected function box(?int $boxId, ?int $currencyId, string $field = 'cash_box_id'): CashBox
    {
        $box = $boxId === null ? null : CashBox::query()->find($boxId);
        if ($box === null || ! $box->is_active) {
            $this->fail($field, 'الصندوق غير موجود أو غير فعّال.');
        }
        if ($currencyId !== null && ! CashBoxCurrency::query()->where('cash_box_id', $box->id)->where('currency_id', $currencyId)->exists()) {
            $this->fail($field, 'العملة '.$this->code($currencyId).' غير مفعّلة في الصندوق '.$box->name.'.');
        }

        return $box;
    }

    /** The voucher's own amount: a positive amount in a known currency. */
    protected function requireAmount(Voucher $voucher, string $amountField = 'amount', string $currencyField = 'currency_id'): string
    {
        if ($voucher->{$currencyField} === null) {
            $this->fail($currencyField, 'حدّد العملة.');
        }
        $amount = $voucher->{$amountField} === null ? '0' : (string) $voucher->{$amountField};
        if (! Money::isPositive($amount)) {
            $this->fail($amountField, 'المبلغ يجب أن يكون أكبر من صفر.');
        }

        return $amount;
    }

    /** An active party, optionally holding one of `$roles`. */
    protected function party(?int $partyId, array $roles = [], string $field = 'party_id'): Party
    {
        $party = $partyId === null ? null : Party::query()->find($partyId);
        if ($party === null || ! $party->is_active) {
            $this->fail($field, 'اختر جهة تعامل فعّالة.');
        }
        if ($roles !== [] && ! PartyRole::query()->where('party_id', $party->id)->whereIn('role', $roles)->exists()) {
            $this->fail($field, 'دور جهة التعامل لا يناسب هذا السند.');
        }

        return $party;
    }

    /** An active, postable account that is not a box (cash moves only through treasury vouchers). */
    protected function plainAccount(?int $accountId, string $field = 'account_id'): Account
    {
        $account = $accountId === null ? null : Account::query()->find($accountId);
        if ($account === null || ! $account->is_active || ! $account->is_postable) {
            $this->fail($field, 'اختر حساباً فعّالاً يقبل القيود.');
        }
        if ($account->is_system || CashBox::query()->where('account_id', $account->id)->exists()) {
            $this->fail($field, 'حركة الصناديق تُسجَّل عبر سندات الخزينة، لا عبر حساب الصندوق مباشرة.');
        }

        return $account;
    }

    protected function code(int $currencyId): string
    {
        return (string) Currency::query()->whereKey($currencyId)->value('code');
    }

    protected function decimals(int $currencyId): int
    {
        return (int) Currency::query()->whereKey($currencyId)->value('decimals');
    }

    /** The voucher's analysis dimensions, for the non-box line. */
    protected function dims(Voucher $voucher): array
    {
        return array_intersect_key($voucher->getAttributes(), array_flip(LineDraft::DIMENSIONS));
    }

    /** Fee on a transfer or exchange: from the source box, in a currency enabled on it, to `transfer_fees` (OP-4). */
    protected function feeLines(Voucher $voucher, CashBox $source): array
    {
        if ($voucher->fee_amount === null || Money::isZero((string) $voucher->fee_amount)) {
            return [];
        }
        $currency = $voucher->fee_currency_id ?? $voucher->currency_id;
        $this->box($source->id, $currency, 'fee_currency_id');
        $fee = (string) $voucher->fee_amount;
        if (Money::isNegative($fee)) {
            $this->fail('fee_amount', 'العمولة لا تكون سالبة.');
        }

        return [
            LineDraft::debit($this->roles->id('transfer_fees'), $currency, $fee, [], 'عمولة'),
            LineDraft::credit($source->account_id, $currency, $fee, [], 'عمولة'),
        ];
    }
}
