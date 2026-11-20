<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Finance\Contracts\PeriodCloseCheck;
use App\Modules\Payroll\Models\PayRun;

/** Finance's pre-close check (spec §9.8): a month closes only after its pay run is approved. */
class PayrollPeriodCheck implements PeriodCloseCheck
{
    public function check(int $year, int $month): array
    {
        $run = PayRun::query()->where('month', sprintf('%04d-%02d', $year, $month))->first(['id', 'status']);
        if ($run === null) {
            return ['blockers' => [], 'warnings' => ['لم يُنشأ كشف رواتب هذا الشهر.']];
        }

        return $run->status === 'approved'
            ? ['blockers' => [], 'warnings' => []]
            : ['blockers' => ['كشف رواتب الشهر غير معتمد.'], 'warnings' => []];
    }
}
