<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Which employees a user may see in HR read screens, from the scope of `<module>.view`:
 * company-wide sees everyone; department scope sees the departments the user manages and their
 * sub-departments, never themselves through that scope (their own data comes through self-service).
 */
class HrScope
{
    public function __construct(private Access $access) {}

    /** @var array<int, Collection<int, int>> */
    private array $cache = [];

    /** @return Collection<int, int>|null null means the whole organisation */
    public function employeeIds(User $user, string $module): ?Collection
    {
        return match ($this->access->scope($user, $module.'.view')) {
            Scope::ALL => null,
            Scope::DEPARTMENT => $this->managedEmployeeIds($user),
            default => collect(),
        };
    }

    public function canSee(User $user, Employee $employee, string $module): bool
    {
        $ids = $this->employeeIds($user, $module);

        return $ids === null || $ids->contains($employee->id);
    }

    public function hasAnyScope(User $user, string $module): bool
    {
        return Scope::covers($this->access->scope($user, $module.'.view'), Scope::DEPARTMENT);
    }

    /** @return Collection<int, int> */
    public function managedEmployeeIds(User $user): Collection
    {
        return $this->cache[$user->id] ??= $this->resolve($user);
    }

    private function resolve(User $user): Collection
    {
        $self = Employee::query()->where('user_id', $user->id)->value('id');
        if ($self === null) {
            return collect();
        }
        $departments = Department::query()->where('manager_employee_id', $self)->pluck('id');
        // Walk down the department tree; the visited set guards against a malformed cycle.
        $frontier = $departments;
        while ($frontier->isNotEmpty()) {
            $frontier = Department::query()->whereIn('parent_id', $frontier)->pluck('id')->diff($departments);
            $departments = $departments->merge($frontier);
        }

        return Employee::query()->whereIn('department_id', $departments)->where('id', '!=', $self)
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
