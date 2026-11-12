<?php

namespace App\Modules\Identity\Contracts;

/**
 * Identity's view of employee records, implemented by the HR module. Keeping the interface here lets
 * Identity link accounts to employees without depending on HR classes (see deptrac.yaml).
 */
interface EmployeeDirectory
{
    /**
     * Locks the employee for linking and returns the profile fields the account must copy.
     * Throws a validation exception when the employee cannot receive an account.
     *
     * @return array{name: string, phone: ?string}
     */
    public function lockForAccount(int $employeeId): array;

    /** Called inside the same transaction, after the user exists. */
    public function linkAccount(int $employeeId, int $userId): void;

    public function employeeIdForUser(int $userId): ?int;

    /** The signed-in user's own employee profile, already shaped for the API, or null. */
    public function profileForUser(int $userId): mixed;

    /**
     * Accounts that manage at least one department today (an active employee named as the department's manager).
     * Department managers get the department-manager role's grants from this, not from a role assignment.
     *
     * @return list<int>
     */
    public function departmentManagerUserIds(): array;
}
