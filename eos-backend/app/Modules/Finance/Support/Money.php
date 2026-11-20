<?php

namespace App\Modules\Finance\Support;

use InvalidArgumentException;

/**
 * Money arithmetic on decimal strings (bcmath). Amounts are never floats: input is normalised with `of()`, sums and
 * products keep 4 decimals (the column scale), rates 8. Rounding is half away from zero.
 */
final class Money
{
    public const SCALE = 4;

    public const RATE_SCALE = 8;

    /** Normalises input (string, int, or a float from JSON) to a plain decimal string. */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Not a finite amount.');
            }
            $value = sprintf('%.10F', $value);
        }
        $value = trim($value);
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal amount: {$value}");
        }
        $value = ltrim($value, '+');
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ($value === '' || $value === '-' || $value === '-0') {
            return '0';
        }
        if (str_starts_with($value, '.') || str_starts_with($value, '-.')) {
            $value = str_replace('.', '0.', $value);
        }

        return $value;
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::clean(bcadd(self::of($a), self::of($b), $scale));
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::clean(bcsub(self::of($a), self::of($b), $scale));
    }

    /** Product rounded to `$scale` decimals (not truncated). */
    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul(self::of($a), self::of($b), $scale + 6), $scale);
    }

    /** Quotient rounded to `$scale` decimals (not truncated). */
    public static function div(string $a, string $b, int $scale = self::RATE_SCALE): string
    {
        if (self::isZero($b)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::round(bcdiv(self::of($a), self::of($b), $scale + 6), $scale);
    }

    public static function cmp(string $a, string $b, int $scale = self::SCALE): int
    {
        return bccomp(self::of($a), self::of($b), $scale);
    }

    public static function isZero(string $a, int $scale = self::SCALE): bool
    {
        return self::cmp($a, '0', $scale) === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isNegative(string $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function neg(string $a): string
    {
        return self::sub('0', $a, max(self::SCALE, self::decimalsOf($a)));
    }

    public static function abs(string $a): string
    {
        return self::isNegative($a) ? self::neg($a) : self::of($a);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    /** Rounds half away from zero to `$decimals` places. */
    public static function round(string $value, int $decimals): string
    {
        $value = self::of($value);
        if (function_exists('bcround')) {
            return self::clean(bcround($value, $decimals));
        }
        $shift = '0.'.str_repeat('0', $decimals).'5';
        $rounded = str_starts_with($value, '-') ? bcsub($value, $shift, $decimals) : bcadd($value, $shift, $decimals);

        return self::clean($rounded);
    }

    /** Rounds to the nearest multiple of `$step` (e.g. 1000 for SYP salaries), half away from zero. */
    public static function roundStep(string $value, string $step): string
    {
        if (! self::isPositive($step)) {
            return self::of($value);
        }
        $units = self::round(bcdiv(self::of($value), self::of($step), 10), 0);

        return self::clean(bcmul($units, self::of($step), self::SCALE));
    }

    /** @param  iterable<string>  $values */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), $scale);
        }

        return self::clean($total);
    }

    /** Thousands-separated display (messages only), e.g. 25,050.5 → "25,050.50" with 2 decimals. */
    public static function format(string $value, int $decimals = 2): string
    {
        $value = self::round($value, $decimals);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));
        $fraction = $decimals > 0 ? '.'.str_pad($fraction, $decimals, '0') : '';

        return ($negative ? '-' : '').$whole.$fraction;
    }

    private static function decimalsOf(string $value): int
    {
        $dot = strpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    /** Drops trailing zeros so values compare and serialise the same way: "100.5000" → "100.5", "-0" → "0". */
    private static function clean(string $value): string
    {
        return self::of($value);
    }
}
