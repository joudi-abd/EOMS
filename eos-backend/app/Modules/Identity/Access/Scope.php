<?php

namespace App\Modules\Identity\Access;

/**
 * How far a granted permission reaches. Wider scopes include the narrower ones.
 *
 * - all: the whole company.
 * - department: the departments the user manages (and their sub-departments).
 * - own: the user's own records only.
 */
final class Scope
{
    public const OWN = 'own';

    public const DEPARTMENT = 'department';

    public const ALL = 'all';

    /** Narrowest first. */
    public const ORDER = [self::OWN, self::DEPARTMENT, self::ALL];

    public static function rank(?string $scope): int
    {
        $rank = array_search($scope, self::ORDER, true);

        return $rank === false ? -1 : $rank;
    }

    public static function wider(?string $a, ?string $b): ?string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }

    public static function narrower(string $a, string $b): string
    {
        return self::rank($a) <= self::rank($b) ? $a : $b;
    }

    /** True when `$held` reaches at least as far as `$needed`. */
    public static function covers(?string $held, string $needed): bool
    {
        return $held !== null && self::rank($held) >= self::rank($needed);
    }
}
