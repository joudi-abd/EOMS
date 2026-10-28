<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

class EmployeeUserProvisioningService
{
    public function __construct(private AuditLogger $audit) {}

    public function createEmployeeUser(string $name, string $email, string $password, ?string $phone): User
    {
        if ($phone !== null && User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['رقم هاتف الموظف مستخدم لحساب آخر.'],
            ]);
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'phone' => $phone,
            'status' => 'active',
        ]);
        $user->assignRole('employee');
        $this->audit->log('user.created', $user, null, [
            'id' => $user->id,
            'roles' => ['employee'],
        ]);

        return $user;
    }
}
