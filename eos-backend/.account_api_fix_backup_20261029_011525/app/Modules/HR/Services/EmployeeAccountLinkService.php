<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Identity\Services\EmployeeUserProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAccountLinkService
{
    public function __construct(
        private EmployeeUserProvisioningService $accounts,
        private AuditLogger $audit
    ) {}

    public function create(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($employee->user_id !== null) {
                throw ValidationException::withMessages([
                    'account' => ['هذا الموظف مرتبط بحساب مسبقًا.'],
                ]);
            }
            if ($employee->status === 'terminated') {
                throw ValidationException::withMessages([
                    'employee' => ['لا يمكن إنشاء حساب لموظف منتهية خدمته.'],
                ]);
            }

            $user = $this->accounts->createEmployeeUser(
                $employee->full_name,
                $data['email'],
                $data['password'],
                $employee->phone
            );
            $employee->user_id = $user->id;
            $employee->save();

            $this->audit->log('employee.account_linked', $employee, null, [
                'employee_id' => $employee->id,
                'user_id' => $user->id,
            ]);

            return $employee->refresh();
        }, 3);
    }
}
