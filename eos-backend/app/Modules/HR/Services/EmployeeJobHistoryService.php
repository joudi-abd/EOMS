<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class EmployeeJobHistoryService
{
    public function recordHire(Employee $employee): EmployeeJobHistory
    {
        return EmployeeJobHistory::create([
            'employee_id' => $employee->id,
            'event_type' => 'hired',
            'new_department_id' => $employee->department_id,
            'new_job_title_id' => $employee->job_title_id,
            'new_manager_id' => $employee->manager_id,
            'effective_date' => $employee->hire_date->format('Y-m-d'),
            'recorded_by_user_id' => auth()->id(),
        ]);
    }

    // Must be called after fill(), before save(), inside EmployeeService transaction.

    public function recordAssignmentChange(
        Employee $employee,
        string $effectiveDate,
        string $reason
    ): ?EmployeeJobHistory {
        if (! $employee->isDirty([
            'department_id',
            'job_title_id',
            'manager_id',
        ])) {
            return null;
        }

        $lastEffectiveDate = EmployeeJobHistory::query()
            ->where('employee_id', $employee->id)
            ->max('effective_date');

        if ($lastEffectiveDate !== null && $effectiveDate < $lastEffectiveDate) {
            throw ValidationException::withMessages([
                'effective_date' => [
                    'تاريخ السريان لا يمكن أن يسبق آخر تغيير وظيفي مسجل.',
                ],
            ]);
        }

        $old = $employee->getRawOriginal();

        return EmployeeJobHistory::create([
            'employee_id' => $employee->id,
            'event_type' => 'assignment_changed',
            'reason' => trim($reason),

            'old_department_id' => $old['department_id'] ?? null,
            'new_department_id' => $employee->department_id,

            'old_job_title_id' => $old['job_title_id'] ?? null,
            'new_job_title_id' => $employee->job_title_id,

            'old_manager_id' => $old['manager_id'] ?? null,
            'new_manager_id' => $employee->manager_id,

            'effective_date' => $effectiveDate,
            'recorded_by_user_id' => auth()->id(),
        ]);
    }

    public function paginate(Employee $employee): LengthAwarePaginator
    {
        return EmployeeJobHistory::where('employee_id', $employee->id)
            ->orderByDesc('effective_date')->orderByDesc('id')->paginate(20);
    }
}
