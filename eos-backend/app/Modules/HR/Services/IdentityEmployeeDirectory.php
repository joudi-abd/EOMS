<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/** HR's implementation of the account ↔ employee link Identity needs. */
class IdentityEmployeeDirectory implements EmployeeDirectory
{
    public function __construct(private AuditLogger $audit) {}

    public function lockForAccount(int $employeeId): array
    {
        // Lock before creating the user to prevent duplicate links.
        $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['employee_id' => ['هذا الموظف مرتبط بحساب مسبقاً.']]);
        }
        if ($employee->status === 'terminated') {
            throw ValidationException::withMessages(['employee_id' => ['لا يمكن إنشاء حساب لموظف انتهت خدمته.']]);
        }
        if ($employee->phone !== null && User::query()->where('phone', $employee->phone)->exists()) {
            throw ValidationException::withMessages(['phone' => ['رقم هاتف الموظف مستخدم لحساب آخر.']]);
        }

        return ['name' => $employee->full_name, 'phone' => $employee->phone];
    }

    public function linkAccount(int $employeeId, int $userId): void
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $employee->user_id = $userId;
        $employee->save();
        $this->audit->log('employee.account_linked', $employee, null, ['employee_id' => $employee->id, 'user_id' => $userId]);
    }

    public function employeeIdForUser(int $userId): ?int
    {
        $id = Employee::query()->where('user_id', $userId)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function profileForUser(int $userId): mixed
    {
        $employee = Employee::query()->where('user_id', $userId)->with(['department:id,name', 'jobTitle:id,name'])->first();

        return $employee ? new EmployeeResource($employee) : null;
    }
}
