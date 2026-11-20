<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Support\Money;

/** Database sums (strings, floats on SQLite, or null) as 4-decimal money strings. */
final class Sums
{
    public static function num(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return Money::round(Money::of(is_int($value) || is_float($value) ? $value : (string) $value), Money::SCALE);
    }
}
