<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\Employee;

/**
 * Refreshes attendance days already materialised for dates touched by a leave or calendar change.
 * Draft days are recalculated; approved days only gain pending_changes (the calculator never rewrites them).
 * Days not yet materialised need nothing: their first calculation already sees the new leave state.
 * Callers run inside their own transaction and already hold the employee lock where relevant.
 */
class AttendanceLeaveSync
{
    public function __construct(private AttendanceCalculator $calculator) {}

    /** @param iterable<string> $dates */
    public function employeeDates(int $employeeId, iterable $dates): void
    {
        $dates = collect($dates)->map(fn ($date) => substr((string) $date, 0, 10))->unique()->sort()->values();
        if ($dates->isEmpty()) {
            return;
        }
        $existing = AttendanceDay::query()->where('employee_id', $employeeId)->whereIn('work_date', $dates->all())
            ->orderBy('work_date')->get();
        if ($existing->isEmpty()) {
            return;
        }
        $employee = Employee::query()->findOrFail($employeeId);
        foreach ($existing as $day) {
            $this->calculator->calculate($employee, $day->work_date->format('Y-m-d'));
        }
    }

    public function date(string $date): void
    {
        // Same employee-id order as every other multi-employee lock in the module.
        $employeeIds = AttendanceDay::query()->where('work_date', $date)->orderBy('employee_id')->pluck('employee_id');
        foreach ($employeeIds as $employeeId) {
            $this->calculator->calculate(Employee::query()->findOrFail($employeeId), $date);
        }
    }
}
