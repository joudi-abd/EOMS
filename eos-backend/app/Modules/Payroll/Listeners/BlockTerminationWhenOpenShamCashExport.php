<?php

namespace App\Modules\Payroll\Listeners;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayRunLine;
use Illuminate\Validation\ValidationException;

/**
 * An open Sham Cash file reserves unpaid salary lines; terminating the employee first would leave
 * a stranded reservation. Cancel or confirm the file before ending service.
 */
class BlockTerminationWhenOpenShamCashExport
{
    public function handle(EmployeeTerminating $event): void
    {
        $openLineIds = PayExport::query()->whereNull('cancelled_at')->whereNull('confirmed_at')
            ->pluck('line_ids')->flatten()->map(fn ($id) => (int) $id)->unique()->values();
        if ($openLineIds->isEmpty()) {
            return;
        }
        if (PayRunLine::query()->where('employee_id', $event->employee->id)->whereIn('id', $openLineIds->all())->exists()) {
            throw ValidationException::withMessages([
                'payroll' => ['الموظف مدرج في ملف شام كاش مفتوح؛ أكّد الملف أو ألغه قبل إنهاء الخدمة.'],
            ]);
        }
    }
}
