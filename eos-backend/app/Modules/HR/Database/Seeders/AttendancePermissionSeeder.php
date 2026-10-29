<?php

namespace App\Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AttendancePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['attendance.view', 'attendance.manage', 'attendance.adjust', 'attendance.approve'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        // Additive deployment seeder: preserve any existing custom role permissions.
        foreach (['system_admin', 'hr_manager'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->givePermissionTo(['attendance.view', 'attendance.manage', 'attendance.adjust', 'attendance.approve']);
        }
        Role::firstOrCreate(['name' => 'company_manager', 'guard_name' => 'web'])
            ->givePermissionTo(['attendance.view', 'attendance.approve']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
