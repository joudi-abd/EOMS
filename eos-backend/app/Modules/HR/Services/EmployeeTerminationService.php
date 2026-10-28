<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Identity\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTerminationService
{
    public function __construct(
        private UserService $users,
        private AuditLogger $audit
    ) {}

    public function terminate(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($employee->status === 'terminated') {
                throw ValidationException::withMessages([
                    'employee' => ['خدمة الموظف منتهية بالفعل.'],
                ]);
            }
            if ($employee->hire_date && $data['termination_date'] < $employee->hire_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'termination_date' => ['لا يمكن أن يسبق الإنهاء تاريخ التعيين.'],
                ]);
            }
            // Keep contract workflow explicit: settle active/pending contract decisions first.
            if (EmploymentContract::query()->where('employee_id', $employee->id)
                ->where('status', 'active')->exists()) {
                throw ValidationException::withMessages([
                    'contracts' => ['يجب إنهاء العقود الفعالة قبل إنهاء خدمة الموظف.'],
                ]);
            }

            if ($employee->user_id !== null) {
                $linkedUser = User::query()->findOrFail($employee->user_id);
                $this->users->setStatus($linkedUser, 'disabled', $actor);
            }

            $employee->update([
                'status' => 'terminated',
                'termination_date' => $data['termination_date'],
                'termination_reason' => $data['reason'],
            ]);

            $this->audit->log('employee.terminated', $employee, null, [
                'employee_id' => $employee->id,
                'termination_date' => $data['termination_date'],
            ]);

            return $employee->refresh();
        }, 3);
    }
}
