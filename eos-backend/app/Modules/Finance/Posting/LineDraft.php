<?php

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Support\Money;

/**
 * One line of an entry before posting. Exactly one of debit/credit is positive. `currency` is a code or a
 * sys_currencies id. `dims` keys: party_id, project_id, project_cost_category_id, expense_category_id, asset_id,
 * employee_id, contractor_agreement_id, custody_id. `rateToBase` is set only by reversals (they keep the original
 * line's rate); `branchId` defaults to the entry's branch.
 */
final readonly class LineDraft
{
    public const DIMENSIONS = ['party_id', 'project_id', 'project_cost_category_id', 'expense_category_id', 'asset_id', 'employee_id',
        'contractor_agreement_id', 'custody_id'];

    public string $debit;

    public string $credit;

    /** @var array<string, int|null> */
    public array $dims;

    public function __construct(
        public int $accountId,
        public int|string $currency,
        string $debit = '0',
        string $credit = '0',
        array $dims = [],
        public ?string $memo = null,
        public ?string $dealRate = null,
        public ?string $rateToBase = null,
        public ?int $branchId = null,
    ) {
        $this->debit = Money::of($debit);
        $this->credit = Money::of($credit);
        $clean = [];
        foreach (self::DIMENSIONS as $key) {
            $clean[$key] = isset($dims[$key]) && $dims[$key] !== '' ? (int) $dims[$key] : null;
        }
        $this->dims = $clean;
    }

    public static function debit(int $accountId, int|string $currency, string $amount, array $dims = [], ?string $memo = null, ?string $dealRate = null): self
    {
        return new self($accountId, $currency, $amount, '0', $dims, $memo, $dealRate);
    }

    public static function credit(int $accountId, int|string $currency, string $amount, array $dims = [], ?string $memo = null, ?string $dealRate = null): self
    {
        return new self($accountId, $currency, '0', $amount, $dims, $memo, $dealRate);
    }

    /** A positive amount debits, a negative one credits its absolute value; zero gives null (no line). */
    public static function signed(int $accountId, int|string $currency, string $amount, array $dims = [], ?string $memo = null, ?string $dealRate = null): ?self
    {
        if (Money::isZero($amount)) {
            return null;
        }

        return Money::isPositive($amount)
            ? self::debit($accountId, $currency, $amount, $dims, $memo, $dealRate)
            : self::credit($accountId, $currency, Money::abs($amount), $dims, $memo, $dealRate);
    }
}
