<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class EmploymentPeriodService
{
    public function today(): string
    {
        $zone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone') ?? config('app.timezone');

        return CarbonImmutable::now($zone)->toDateString();
    }

    public function bounds(Employee $employee, string $date): array
    {
        $employee->loadMissing('servicePeriods');
        $periods = $employee->servicePeriods->sortBy('start_date');
        $period = $periods->first(fn ($row) => $row->start_date->format('Y-m-d') <= $date
            && (! $row->end_date || $row->end_date->format('Y-m-d') >= $date))
            ?? $periods->last(fn ($row) => $row->start_date->format('Y-m-d') <= $date)
            ?? $periods->first();

        return [$period?->start_date?->format('Y-m-d') ?? $employee->hire_date->format('Y-m-d'),
            $period ? $period->end_date?->format('Y-m-d') : $employee->termination_date?->format('Y-m-d')];
    }

    /** Caller holds the employee row lock. */
    public function close(Employee $employee, string $date): void
    {
        $period = EmployeeServicePeriod::query()->where('employee_id', $employee->id)->whereNull('end_date')->first();
        $start = $period?->start_date->format('Y-m-d') ?? $employee->hire_date->format('Y-m-d');
        if ($date < $start) {
            throw ValidationException::withMessages(['termination_date' => ['لا يمكن أن يسبق الإنهاء بداية فترة الخدمة الحالية.']]);
        }
        if ($period) {
            $period->update(['end_date' => $date]);
        } else {
            if (EmployeeServicePeriod::query()->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['termination_date' => ['لا توجد فترة خدمة مفتوحة؛ راجع سجل الموظف.']]);
            }
            EmployeeServicePeriod::create(['employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $date]);
        }
    }

    public function validateContractStart(Employee $employee, string $start, string $field = 'start_date'): void
    {
        $periods = EmployeeServicePeriod::query()->where('employee_id', $employee->id)->get();
        $current = $periods->firstWhere('end_date', null);
        if ($periods->count() > 1 && $current && $start < $current->start_date->format('Y-m-d')) {
            throw ValidationException::withMessages([$field => ['تاريخ السريان لا يمكن أن يسبق بداية فترة الخدمة الحالية.']]);
        }
    }
}
