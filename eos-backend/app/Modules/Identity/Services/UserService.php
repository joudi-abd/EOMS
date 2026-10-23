<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $roles = Arr::pull($data, 'roles', []);

            $user = User::create($data);
            $user->syncRoles($roles);

            $this->audit->log('user.created', $user, null, $user->toArray());

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $roles = Arr::pull($data, 'roles');

            // كلمة المرور لا تدخل سجل التدقيق أبداً
            $changed = Arr::except($data, ['password']);
            $old = Arr::only($user->getAttributes(), array_keys($changed));

            $user->update($data);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            $this->audit->log('user.updated', $user, $old, Arr::only($user->getAttributes(), array_keys($changed)));

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

        return DB::transaction(function () use ($user, $status) {
            $old = $user->status;

            $user->update(['status' => $status]);

            // التعطيل يُلغي كل التوكنات فوراً من كل الأجهزة
            if ($status === 'disabled') {
                $user->tokens()->delete();
            }

            $this->audit->log('user.status_changed', $user, ['status' => $old], ['status' => $status]);

            return $user->refresh();
        });
    }

    public function assignRoles(User $target, array $roles): User
    {
        return DB::transaction(function () use ($target, $roles) {
            $old = $target->getRoleNames()->all();

            $removingAdmin = in_array('system_admin', $old, true) && ! in_array('system_admin', $roles, true);

            if ($removingAdmin) {
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

            $target->syncRoles($roles);

            $this->audit->log('user.roles_changed', $target, ['roles' => $old], ['roles' => $roles]);

            return $target->refresh();
        });
    }
}