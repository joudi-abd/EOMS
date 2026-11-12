<?php

namespace App\Modules\Identity\Contracts;

use Illuminate\Validation\ValidationException;

/** Used when no module provides employee records. */
class NullEmployeeDirectory implements EmployeeDirectory
{
    public function lockForAccount(int $employeeId): array
    {
        throw ValidationException::withMessages(['employee_id' => ['سجلات الموظفين غير متاحة.']]);
    }

    public function linkAccount(int $employeeId, int $userId): void {}

    public function employeeIdForUser(int $userId): ?int
    {
        return null;
    }

    public function profileForUser(int $userId): mixed
    {
        return null;
    }

    public function departmentManagerUserIds(): array
    {
        return [];
    }
}
