<?php

use App\Modules\Identity\Access\DefaultRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become editable records with labels and a kind, and every grant carries a scope (all / department / own).
 *
 * Existing data is carried over:
 * - `x.view_department` grants become `x.view` at department scope;
 * - `roles.assign` becomes `roles.manage`;
 * - nobody holds the department-manager role by hand any more: managing a department grants it;
 * - grants of the shipped roles take the scope the code now defines for them (e.g. tasks for HR is "own");
 * - the company manager's `leaves.approve` (which now means reviewing staff requests) is withdrawn —
 *   it reviews the approvers' requests through `leaves.approve_reviewers`, granted by `permissions:sync`.
 * Permissions no module enforces are removed by `permissions:sync`, which runs after the migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usr_roles', function (Blueprint $table) {
            $table->string('label_ar', 80)->nullable()->after('name');
            $table->string('label_en', 80)->nullable()->after('label_ar');
            $table->string('hint_ar', 300)->nullable()->after('label_en');
            $table->string('hint_en', 300)->nullable()->after('hint_ar');
            $table->string('kind', 12)->default('standard')->after('hint_en');
            $table->boolean('is_default')->default(false)->after('kind');
            $table->unsignedInteger('version')->default(1)->after('is_default');
        });
        Schema::table('usr_role_permissions', function (Blueprint $table) {
            $table->string('scope', 12)->default('all');
        });

        $permission = fn (string $name) => DB::table('usr_permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        foreach (['attendance', 'leaves'] as $module) {
            $narrow = $permission("{$module}.view_department");
            if ($narrow === null) {
                continue;
            }
            $wide = $permission("{$module}.view") ?? DB::table('usr_permissions')->insertGetId(
                ['name' => "{$module}.view", 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('usr_role_permissions')->where('permission_id', $narrow)->pluck('role_id') as $roleId) {
                $exists = DB::table('usr_role_permissions')->where('role_id', $roleId)->where('permission_id', $wide)->exists();
                if (! $exists) {
                    DB::table('usr_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $wide, 'scope' => 'department']);
                }
            }
            DB::table('usr_permissions')->where('id', $narrow)->delete();
        }

        $assign = $permission('roles.assign');
        if ($assign !== null && $permission('roles.manage') === null) {
            DB::table('usr_permissions')->where('id', $assign)->update(['name' => 'roles.manage']);
        }

        foreach (DefaultRoles::all() as $name => $definition) {
            $roleId = DB::table('usr_roles')->where('name', $name)->where('guard_name', 'web')->value('id');
            if ($roleId === null) {
                continue;
            }
            DB::table('usr_roles')->where('id', $roleId)->update([
                'label_ar' => $definition['ar'], 'label_en' => $definition['en'],
                'hint_ar' => $definition['hint_ar'], 'hint_en' => $definition['hint_en'],
                'kind' => $definition['kind'], 'is_default' => true,
            ]);
            foreach ($definition['grants'] as $grant => $scope) {
                $permissionId = $permission($grant);
                if ($permissionId !== null) {
                    DB::table('usr_role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->update(['scope' => $scope]);
                }
            }
            if ($definition['kind'] === 'department') {
                DB::table('usr_user_roles')->where('role_id', $roleId)->delete();
            }
        }

        $company = DB::table('usr_roles')->where('name', 'company_manager')->where('guard_name', 'web')->value('id');
        $approve = $permission('leaves.approve');
        if ($company !== null && $approve !== null) {
            DB::table('usr_role_permissions')->where('role_id', $company)->where('permission_id', $approve)->delete();
        }

        // Direct grants to accounts are not part of the model any more: roles only.
        DB::table('usr_user_permissions')->delete();
    }

    public function down(): void
    {
        Schema::table('usr_role_permissions', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
        Schema::table('usr_roles', function (Blueprint $table) {
            $table->dropColumn(['label_ar', 'label_en', 'hint_ar', 'hint_en', 'kind', 'is_default', 'version']);
        });
    }
};
