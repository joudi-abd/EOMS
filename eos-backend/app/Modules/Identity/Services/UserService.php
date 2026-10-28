<?php

namespace App\Modules\Identity\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Events\UserProfileUpdating;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private AuditLogger $audit) {}

    private function ensureRoleAdministrator(): void
    {
        if (! auth()->user()?->hasRole('system_admin')) {
            throw new AuthorizationException('فقط مدير النظام يستطيع تعيين الأدوار.');
        }
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $roles = Arr::pull($data, 'roles');
            $employeeId = Arr::pull($data, 'employee_id');

            // Keep existing role-authority policy for account creation.
            if ($roles !== null) {
                $this->ensureRoleAdministrator();
            }

            $employee = null;
            if ($employeeId !== null) {
                // Lock before creating the user to prevent duplicate links.
                $employee = Employee::query()
                    ->lockForUpdate()
                    ->findOrFail($employeeId);

                if ($employee->user_id !== null) {
                    throw ValidationException::withMessages([
                        'employee_id' => ['هذا الموظف مرتبط بحساب مسبقاً.'],
                    ]);
                }

                if ($employee->status === 'terminated') {
                    throw ValidationException::withMessages([
                        'employee_id' => ['لا يمكن إنشاء حساب لموظف انتهت خدمته.'],
                    ]);
                }

                // Name and phone always come from HR; ignore client-supplied values.
                $data['name'] = $employee->full_name;
                $data['phone'] = $employee->phone;
                $data['status'] = 'active';

                if ($employee->phone !== null && User::query()
                    ->where('phone', $employee->phone)->exists()) {
                    throw ValidationException::withMessages([
                        'phone' => ['رقم هاتف الموظف مستخدم لحساب آخر.'],
                    ]);
                }

                // No explicit roles: provision regular employee account.
                // Authorized system_admin may explicitly select other roles.
                $roles ??= ['employee'];
            }

            $user = User::create($data);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            if ($employee !== null) {
                $employee->user_id = $user->id;
                $employee->save();

                $this->audit->log('employee.account_linked', $employee, null, [
                    'employee_id' => $employee->id,
                    'user_id' => $user->id,
                ]);
            }

            $this->audit->log('user.created', $user, null, [
                'id' => $user->id,
                'roles' => $user->getRoleNames()->all(),
            ]);

            return $user;
        }, 3);
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $roles = Arr::pull($data, 'roles');

            if ($roles !== null) {
                $this->ensureRoleAdministrator();
            }
            if ($roles !== null) {
                $this->lockSystemAdminRole();
            }

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            // $oldRoles = $user->getRoleNames()->all();
            // if ($roles !== null) {
            //     $this->ensureLastActiveAdminIsPreserved($user, $oldRoles, $roles);
            // }
            // event(new UserProfileUpdating($user, $data));
            // $user->fill($data);

            // $changed = Arr::except($user->getDirty(), ['password']);
            // $old = Arr::only($user->getRawOriginal(), array_keys($changed));
            // $user->save();
            $oldRoles = $user->getRoleNames()->all();
            if ($roles !== null) {
                $this->ensureLastActiveAdminIsPreserved($user, $oldRoles, $roles);
            }
            // Notify other modules before updating the user.
            event(new UserProfileUpdating($user, $data));
            $user->fill($data);
            $changed = Arr::except(
                $user->getDirty(),
                ['password']
            );
            $old = Arr::only(
                $user->getRawOriginal(),
                array_keys($changed)
            );
            $user->save();
            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            if ($user->wasChanged() || ($roles !== null && $oldRoles !== $user->getRoleNames()->all())) {
                $this->audit->log(
                    'user.updated',
                    $user,
                    $old + ($roles !== null ? ['roles' => $oldRoles] : []),
                    $changed + ($roles !== null ? ['roles' => $user->getRoleNames()->all()] : [])
                );
            }

            return $user->refresh();
        });
    }

    public function setStatus(
        User $user,
        string $status,
        User $actor
    ): User {
        return DB::transaction(function () use ($user, $status, $actor) {

            $this->lockSystemAdminRole();

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            if ($status === 'disabled' && $actor->is($user)) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكنك تعطيل حسابك بنفسك.'],
                ]);
            }

            if ($user->hasRole('system_admin')) {
                $this->ensureRoleAdministrator();

                if ($status === 'disabled' && $user->status === 'active') {
                    $otherActiveAdmins = User::role('system_admin')
                        ->where('status', 'active')
                        ->whereKeyNot($user->id)
                        ->count();

                    if ($otherActiveAdmins === 0) {
                        throw ValidationException::withMessages([
                            'status' => [
                                'لا يمكن تعطيل آخر مدير نظام نشط.',
                            ],
                        ]);
                    }
                }
            }

            $old = $user->status;

            $user->update(['status' => $status]);

            if ($status === 'disabled') {
                $user->tokens()->delete();
            }

            $this->audit->log(
                'user.status_changed',
                $user,
                ['status' => $old],
                ['status' => $status]
            );

            return $user->refresh();
        });
    }

    public function assignRoles(User $target, array $roles): User
    {
        $this->ensureRoleAdministrator();

        return DB::transaction(function () use ($target, $roles) {
            $this->lockSystemAdminRole();

            $target = User::query()
                ->lockForUpdate()
                ->findOrFail($target->id);

            $old = $target->getRoleNames()->all();

            $this->ensureLastActiveAdminIsPreserved(
                $target,
                $old,
                $roles
            );

            $target->syncRoles($roles);

            $this->audit->log(
                'user.roles_changed',
                $target,
                ['roles' => $old],
                ['roles' => $roles]
            );

            return $target->refresh();
        });
    }

    private function ensureLastActiveAdminIsPreserved(User $target, array $old, array $roles): void
    {
        if (! in_array('system_admin', $old, true)
            || in_array('system_admin', $roles, true)
            || $target->status !== 'active') {
            return;
        }

        $otherActiveAdmins = User::role('system_admin')
            ->where('status', 'active')
            ->where('id', '!=', $target->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'roles' => ['لا يمكن إزالة دور مدير النظام من آخر مدير نشط.'],
            ]);
        }
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('name')
            ->paginate(20);
    }

    private function lockSystemAdminRole(): void
    {
        $role = DB::table('usr_roles')
            ->where('name', 'system_admin')
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->first();

        if ($role === null) {
            throw new \RuntimeException(
                'System admin role is missing. Run RolePermissionSeeder.'
            );
        }
    }
}
