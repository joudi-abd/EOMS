<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\DefaultRoles;
use App\Modules\Identity\Access\RoleService;
use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Events\UserProfileUpdating;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private AuditLogger $audit,
        private EmployeeDirectory $employees,
        private Access $access,
        private RoleService $roles,
    ) {}

    /** Giving roles needs `roles.manage`, and every new role must sit within the actor's own permissions. */
    private function ensureMayAssign(array $roles, array $current = []): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $this->access->allows($actor, 'roles.manage')) {
            throw new AuthorizationException('تعيين الأدوار يتطلب صلاحية إدارة الأدوار.');
        }
        $this->roles->ensureAssignable($actor, $roles, $current);
    }

    private function ensureAdministrator(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $this->access->isAdmin($actor)) {
            throw new AuthorizationException('فقط مدير النظام يستطيع تعديل حساب مدير نظام.');
        }
    }

    private function forget(User $user): void
    {
        DB::afterCommit(fn () => $this->access->flush($user->id));
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $roles = Arr::pull($data, 'roles');
            $employeeId = Arr::pull($data, 'employee_id');

            // Keep existing role-authority policy for account creation.
            if ($roles !== null) {
                $this->ensureMayAssign($roles);
            }

            if ($employeeId !== null) {
                // Name and phone always come from HR; ignore client-supplied values.
                $profile = $this->employees->lockForAccount((int) $employeeId);
                $data['name'] = $profile['name'];
                $data['phone'] = $profile['phone'];
                $data['status'] = 'active';
                // No explicit roles: a regular employee account. Other roles need roles.manage (checked above).
                $roles ??= [DefaultRoles::EMPLOYEE];
            }

            $user = User::create($data);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            if ($employeeId !== null) {
                $this->employees->linkAccount((int) $employeeId, $user->id);
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
                $this->lockSystemAdminRole();
            }

            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $oldRoles = $user->getRoleNames()->all();
            if ($roles !== null) {
                $this->ensureMayAssign($roles, $oldRoles);
                $this->ensureMayTouch($user);
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
                $this->forget($user);
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

            if ($user->hasRole(DefaultRoles::ADMIN)) {
                $this->ensureAdministrator();

                if ($status === 'disabled' && $user->status === 'active') {
                    $otherActiveAdmins = User::role(DefaultRoles::ADMIN)
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
        return DB::transaction(function () use ($target, $roles) {
            $this->lockSystemAdminRole();

            $target = User::query()
                ->lockForUpdate()
                ->findOrFail($target->id);

            $old = $target->getRoleNames()->all();
            $this->ensureMayAssign($roles, $old);
            $this->ensureMayTouch($target);

            $this->ensureLastActiveAdminIsPreserved(
                $target,
                $old,
                $roles
            );

            $target->syncRoles($roles);
            $this->forget($target);

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
        if (! in_array(DefaultRoles::ADMIN, $old, true)
            || in_array(DefaultRoles::ADMIN, $roles, true)
            || $target->status !== 'active') {
            return;
        }

        $otherActiveAdmins = User::role(DefaultRoles::ADMIN)
            ->where('status', 'active')
            ->where('id', '!=', $target->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'roles' => ['لا يمكن إزالة دور مدير النظام من آخر مدير نشط.'],
            ]);
        }
    }

    /** Only an administrator changes an administrator's roles. */
    private function ensureMayTouch(User $target): void
    {
        if ($target->hasRole(DefaultRoles::ADMIN)) {
            $this->ensureAdministrator();
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
            ->where('name', DefaultRoles::ADMIN)
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
