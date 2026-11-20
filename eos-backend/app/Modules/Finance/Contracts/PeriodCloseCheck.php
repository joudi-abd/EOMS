<?php

namespace App\Modules\Finance\Contracts;

/**
 * A pre-close check another module contributes (spec §9.8), e.g. Payroll's "the month's pay run is not approved".
 * Modules that depend on Finance tag their implementation with PeriodCloseCheck::TAG in their service provider, so
 * Finance asks them without depending on them.
 */
interface PeriodCloseCheck
{
    public const TAG = 'finance.period_close_checks';

    /** @return array{blockers: list<string>, warnings: list<string>} */
    public function check(int $year, int $month): array;
}
