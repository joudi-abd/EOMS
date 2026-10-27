<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('identity.admin.email');
        $password = config('identity.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL أو ADMIN_PASSWORD غير مضبوط في .env، تم تخطي إنشاء مدير النظام.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => config('identity.admin.name'),
                'password' => $password,
                'status' => 'active',
            ]
        );

        $user->syncRoles(['system_admin']);
    }
}
