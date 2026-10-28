<?php

namespace App\Modules\Identity\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const MODULES = [
        'employees' => ['view', 'manage', 'view_sensitive', 'view_financial'],
        'attendance' => ['view', 'manage', 'adjust'],
        'leaves' => ['view', 'approve'],
        'payroll' => ['view', 'manage', 'approve'],
        'finance' => ['view', 'manage'],
        'projects' => ['view', 'manage'],
        'purchases' => ['view', 'manage', 'approve'],
        'assets' => ['view', 'manage'],
        'documents' => ['view', 'manage'],
        'meetings' => ['view', 'manage'],
        'reports' => ['view'],
        'audit' => ['view'],
        'settings' => ['view', 'manage'],
        'users' => ['manage'],
        'roles' => ['assign'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = collect(self::MODULES)
            ->flatMap(fn ($actions, $module) => collect($actions)->map(fn ($a) => "{$module}.{$a}"))
            ->values();

        foreach ($all as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $roles = [
            'system_admin' => $all->all(),
            'company_manager' => $all
                ->filter(fn ($p) => (str_ends_with($p, '.view') || str_ends_with($p, '.approve')))
                ->values()->all(),
            'hr_manager' => [
                'employees.view', 'employees.manage', 'employees.view_sensitive',
                'attendance.view', 'attendance.manage', 'attendance.adjust',
                'leaves.view', 'leaves.approve',
                'documents.view', 'documents.manage', 'reports.view',
            ],
            'finance_manager' => [
                'payroll.view', 'payroll.manage',
                'finance.view', 'finance.manage',
                'purchases.view', 'purchases.approve', 'reports.view',
            ],
            'project_manager' => [
                'projects.view', 'projects.manage',
                'meetings.view', 'meetings.manage', 'employees.view',
                'documents.view', 'documents.manage', 'reports.view',
            ],
            'engineer' => ['projects.view', 'meetings.view', 'documents.view'],
            'purchasing_officer' => [
                'purchases.view', 'purchases.manage', 'assets.view',
            ],
            'employee' => [],
        ];

        foreach ($roles as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
