<?php

namespace App\Modules\Payroll\Listeners;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayRunLine;
use Illuminate\Validation\ValidationException;

/**
 * An open Sham Cash file reserves unpaid salary lines; terminating the employee first would leave
 * a stranded reservation. Cancel or confirm the file before ending service.
 *
 * Lock order matches ShamCashService export/confirm for the overlapping rows: open exports by id,
 * then the employee's lines that appear in those files (by id). Export itself locks run → exports → lines;
 * holding exports before lines here avoids racing a concurrent export that already holds the run.
 */
class BlockTerminationWhenOpenShamCashExport
{
    public function handle(EmployeeTerminating $event): void
    {
        $open = PayExport::query()->whereNull('cancelled_at')->whereNull('confirmed_at')
            ->orderBy('id')->lockForUpdate()->get();
        $openLineIds = $open->pluck('line_ids')->flatten()->map(fn ($id) => (int) $id)->unique()->values();
        if ($openLineIds->isEmpty()) {
            return;
        }
        $held = PayRunLine::query()->where('employee_id', $event->employee->id)
            ->whereIn('id', $openLineIds->all())->orderBy('id')->lockForUpdate()->exists();
        if ($held) {
            throw ValidationException::withMessages([
                'payroll' => ['الموظف مدرج في ملف شام كاش مفتوح؛ أكّد الملف أو ألغه قبل إنهاء الخدمة.'],
            ]);
        }
    }
}
