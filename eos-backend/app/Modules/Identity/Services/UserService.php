<?php

namespace App\Modules\Identity\Services;

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

            if ($roles !== null) {
                $this->ensureRoleAdministrator();
            }

            $user = User::create($data);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            $this->audit->log('user.created', $user, null, [
                'id' => $user->id,
                'roles' => $user->getRoleNames()->all(),
            ]);

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $roles = Arr::pull($data, 'roles');

            if ($roles !== null) {
                $this->ensureRoleAdministrator();
            }

            $oldRoles = $user->getRoleNames()->all();
            if ($roles !== null) {
                $this->ensureLastActiveAdminIsPreserved($user, $oldRoles, $roles);
            }
            $user->fill($data);

            $changed = Arr::except($user->getDirty(), ['password']);
            $old = Arr::only($user->getRawOriginal(), array_keys($changed));
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

    public function setStatus(User $user, string $status, User $actor): User
    {
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
                    ->where('id', '!=', $user->id)
                    ->count();

                if ($otherActiveAdmins === 0) {
                    throw ValidationException::withMessages([
                        'status' => ['لا يمكن تعطيل آخر مدير نظام نشط.'],
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($user, $status) {
            $old = $user->status;
            $user->update(['status' => $status]);

            if ($status === 'disabled') {
                $user->tokens()->delete();
            }

            $this->audit->log('user.status_changed', $user, ['status' => $old], ['status' => $status]);

            return $user->refresh();
        });
    }

    public function assignRoles(User $target, array $roles): User
    {
        $this->ensureRoleAdministrator();

        return DB::transaction(function () use ($target, $roles) {
            $old = $target->getRoleNames()->all();
            $this->ensureLastActiveAdminIsPreserved($target, $old, $roles);
            $target->syncRoles($roles);
            $this->audit->log('user.roles_changed', $target, ['roles' => $old], ['roles' => $roles]);

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
}
