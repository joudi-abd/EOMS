<?php

namespace Tests\Feature\Identity;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\AccessSync;
use App\Modules\Identity\Access\DefaultRoles;
use App\Modules\Identity\Access\PermissionCatalog;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(array $roles): User
    {
        $user = User::factory()->create();
        $user->syncRoles($roles);
        app(Access::class)->flush();

        return $user;
    }

    private function role(string $name): array
    {
        Sanctum::actingAs($this->user([DefaultRoles::ADMIN]));
        $row = collect($this->getJson('/api/v1/access/roles')->assertOk()->json('data'))->firstWhere('name', $name);
        $this->assertNotNull($row, $name);

        return $row;
    }

    private function grants(User $user): array
    {
        app(Access::class)->flush();

        return app(Access::class)->grants($user->fresh());
    }

    // ── The catalogue is the code's truth ─────────────────────────────────────

    public function test_every_catalogued_permission_is_enforced_and_every_checked_permission_is_catalogued(): void
    {
        $code = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() === 'php' && ! str_contains($path, '/Identity/Access/') && ! str_contains($path, '/Migrations/')) {
                $code .= file_get_contents($path)."\n";
            }
        }
        $code .= file_get_contents(base_path('routes/channels.php'));

        $unused = array_values(array_filter(PermissionCatalog::names(), fn ($name) => ! str_contains($code, "'{$name}'") && ! str_contains($code, "perm:{$name}")));
        $this->assertSame([], $unused, 'Catalogued permissions nothing enforces');

        preg_match_all('/perm:([a-z_]+\\.[a-z_.]+)/', $code, $middleware);
        preg_match_all("/(?:->can|->allows|->covers|->scope|->usersWith|permissionFor)\\((?:[^,()]+, )?'([a-z_]+\\.[a-z_.]+)'/", $code, $calls);
        $unknown = array_values(array_unique(array_filter([...$middleware[1], ...$calls[1]], fn ($name) => ! PermissionCatalog::has($name))));
        $this->assertSame([], $unknown, 'Permissions checked in code but missing from the catalogue');
    }

    public function test_sync_is_repeatable_keeps_edits_and_removes_what_the_code_dropped(): void
    {
        DB::table('usr_permissions')->insert(['name' => 'projects.view', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $hr = DB::table('usr_roles')->where('name', 'hr_manager')->value('id');
        $payrollManage = DB::table('usr_permissions')->where('name', 'payroll.manage')->value('id');
        DB::table('usr_role_permissions')->where('role_id', $hr)->where('permission_id', $payrollManage)->delete(); // an admin's edit

        $result = app(AccessSync::class)->run();
        app(AccessSync::class)->run();

        $this->assertSame(['projects.view'], $result['removed']);
        $this->assertSame(count(PermissionCatalog::names()), DB::table('usr_permissions')->count());
        $this->assertFalse(DB::table('usr_role_permissions')->where('role_id', $hr)->where('permission_id', $payrollManage)->exists());

        // A permission the catalogue gains reaches the shipped roles that define it.
        DB::table('usr_permissions')->where('name', 'leaves.approve_reviewers')->delete();
        app(AccessSync::class)->run();
        $company = DB::table('usr_roles')->where('name', 'company_manager')->value('id');
        $this->assertSame('all', DB::table('usr_role_permissions as rp')->join('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $company)->where('p.name', 'leaves.approve_reviewers')->value('rp.scope'));

        // Reset puts the shipped definition back.
        app(AccessSync::class)->run(reset: true);
        $this->assertTrue(DB::table('usr_role_permissions')->where('role_id', $hr)->where('permission_id', $payrollManage)->exists());
    }

    public function test_the_administrator_holds_every_permission_at_its_widest_scope(): void
    {
        $admin = $this->user([DefaultRoles::ADMIN]);
        $grants = $this->grants($admin);

        $this->assertSame(PermissionCatalog::names(), array_keys($grants));
        $this->assertSame('all', $grants['tasks.view']);
        $this->assertTrue($admin->can('roles.manage'));
    }

    public function test_me_returns_the_effective_permissions_with_their_scopes(): void
    {
        $hr = $this->user(['employee', 'hr_manager']);
        Sanctum::actingAs($hr);

        // Permission names hold dots, so read the map rather than JSON paths.
        $permissions = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.user.permissions');
        $this->assertSame('all', $permissions['leaves.view']);
        $this->assertSame('own', $permissions['tasks.view']);
        $this->assertArrayNotHasKey('finance.view', $permissions);
    }

    // ── Department managers come from the department data ──────────────────────

    public function test_managing_a_department_grants_the_department_role_at_department_scope_only(): void
    {
        $engineering = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $mona = $this->user(['employee']);
        $employee = Employee::create(['employee_no' => 'E-1', 'user_id' => $mona->id, 'first_name' => 'Mona', 'last_name' => 'T',
            'hire_date' => '2025-01-01', 'national_id' => 'E-1', 'national_id_hash' => hash('sha256', 'E-1'), 'department_id' => $engineering->id]);

        $this->assertArrayNotHasKey('leaves.view', $this->grants($mona));

        $engineering->update(['manager_employee_id' => $employee->id]);
        $grants = $this->grants($mona);
        $this->assertSame('department', $grants['leaves.view']);
        $this->assertSame('department', $grants['attendance.view']);
        $this->assertSame('department', $grants['tasks.view']); // wider than the employee role's "own"

        // Even if someone widens the department role, it never reaches past the department.
        Sanctum::actingAs($this->user([DefaultRoles::ADMIN]));
        $role = collect($this->getJson('/api/v1/access/roles')->json('data'))->firstWhere('name', DefaultRoles::DEPARTMENT_MANAGER);
        $saved = $this->patchJson('/api/v1/access/roles/'.DefaultRoles::DEPARTMENT_MANAGER, ['version' => $role['version'],
            'grants' => ['leaves.view' => 'all', 'attendance.view' => 'department']])->assertOk()->json('data.grants');
        $this->assertSame('department', $saved['leaves.view']);

        // And a grant written straight to the database is capped too.
        DB::table('usr_role_permissions')->where('role_id', DB::table('usr_roles')->where('name', DefaultRoles::DEPARTMENT_MANAGER)->value('id'))->update(['scope' => 'all']);
        $this->assertSame('department', $this->grants($mona)['leaves.view']);

        // Department reach opens department lists, not company-wide screens (perm:attendance.view,all).
        Sanctum::actingAs($mona);
        $this->getJson('/api/v1/attendance/days?date=2026-11-03')->assertOk();
        $this->getJson('/api/v1/attendance/policies')->assertForbidden();

        $engineering->update(['manager_employee_id' => null]);
        $this->assertArrayNotHasKey('attendance.view', $this->grants($mona));
    }

    public function test_the_department_role_is_never_assigned_by_hand(): void
    {
        Sanctum::actingAs($this->user([DefaultRoles::ADMIN]));
        $target = $this->user(['employee']);

        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['employee', DefaultRoles::DEPARTMENT_MANAGER]])
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    // ── The roles screen ──────────────────────────────────────────────────────

    public function test_roles_are_listed_for_everyone_with_grants_only_for_editors(): void
    {
        Sanctum::actingAs($this->user(['employee']));
        $row = collect($this->getJson('/api/v1/access/roles')->assertOk()->json('data'))->firstWhere('name', 'hr_manager');
        $this->assertSame('مسؤول الموارد البشرية', $row['label_ar']);
        $this->assertArrayNotHasKey('grants', $row);
        $this->getJson('/api/v1/access/catalog')->assertOk()->assertJsonPath('data.scopes', ['own', 'department', 'all']);

        $this->postJson('/api/v1/access/roles', ['name' => 'x', 'label_ar' => 'س', 'grants' => []])->assertForbidden();
    }

    public function test_an_administrator_creates_edits_and_deletes_a_custom_role(): void
    {
        Sanctum::actingAs($this->user([DefaultRoles::ADMIN]));

        $created = $this->postJson('/api/v1/access/roles', [
            'name' => 'site_supervisor', 'label_ar' => 'مشرف موقع', 'label_en' => 'Site supervisor',
            'grants' => ['tasks.view' => 'all', 'tasks.manage' => 'own', 'employees.view' => 'all'],
        ])->assertCreated()->assertJsonPath('data.kind', 'standard')->json('data');
        $this->assertSame('all', $created['grants']['tasks.view']);

        $this->postJson('/api/v1/access/roles', ['name' => 'bad', 'label_ar' => 'س', 'grants' => ['finance.view' => 'department']])
            ->assertUnprocessable(); // finance.view has no department scope
        $this->postJson('/api/v1/access/roles', ['name' => 'bad', 'label_ar' => 'س', 'grants' => ['projects.view' => 'all']])
            ->assertUnprocessable(); // not in the catalogue

        $this->patchJson('/api/v1/access/roles/site_supervisor', ['version' => $created['version'], 'grants' => ['tasks.view' => 'own']])
            ->assertOk();
        $this->assertSame(['tasks.view' => 'own'], $this->getJson('/api/v1/access/roles')->collect('data')->firstWhere('name', 'site_supervisor')['grants']);
        $this->patchJson('/api/v1/access/roles/site_supervisor', ['version' => $created['version'], 'label_ar' => 'قديم'])
            ->assertConflict();

        $holder = $this->user(['site_supervisor']);
        $version = $created['version'] + 1;
        $this->deleteJson('/api/v1/access/roles/site_supervisor', ['version' => $version])->assertUnprocessable();
        $holder->syncRoles(['employee']);
        $this->deleteJson('/api/v1/access/roles/site_supervisor', ['version' => $version])->assertOk();
        $this->assertDatabaseMissing('usr_roles', ['name' => 'site_supervisor']);
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'role.deleted']);
    }

    public function test_shipped_roles_are_edited_and_restored_but_never_deleted_and_the_admin_role_is_locked(): void
    {
        $hr = $this->role('hr_manager');

        $this->deleteJson('/api/v1/access/roles/hr_manager', ['version' => $hr['version']])->assertUnprocessable();
        $edited = $this->patchJson('/api/v1/access/roles/hr_manager', ['version' => $hr['version'], 'label_ar' => 'الموارد البشرية',
            'grants' => ['employees.view' => 'all']])->assertOk()->json('data');
        $this->assertSame(['employees.view' => 'all'], $edited['grants']);

        $restored = $this->postJson('/api/v1/access/roles/hr_manager/restore', ['version' => $edited['version']])->assertOk()->json('data');
        $this->assertSame('مسؤول الموارد البشرية', $restored['label_ar']);
        $this->assertEquals(DefaultRoles::all()['hr_manager']['grants'], $restored['grants']);

        $admin = $this->role(DefaultRoles::ADMIN);
        $this->patchJson('/api/v1/access/roles/'.DefaultRoles::ADMIN, ['version' => $admin['version'], 'grants' => []])->assertUnprocessable();
    }

    public function test_role_editors_cannot_give_more_than_they_hold(): void
    {
        DB::table('usr_role_permissions')->insert([
            'role_id' => DB::table('usr_roles')->where('name', 'hr_manager')->value('id'),
            'permission_id' => DB::table('usr_permissions')->where('name', 'roles.manage')->value('id'), 'scope' => 'all',
        ]);
        $hr = $this->user(['hr_manager']);
        Sanctum::actingAs($hr);

        // Within reach: fine. Beyond it — a permission HR lacks, or a wider scope — refused.
        $this->postJson('/api/v1/access/roles', ['name' => 'hr_clerk', 'label_ar' => 'كاتب', 'grants' => ['employees.view' => 'all']])->assertCreated();
        $this->postJson('/api/v1/access/roles', ['name' => 'cashier', 'label_ar' => 'صندوق', 'grants' => ['finance.view' => 'all']])
            ->assertUnprocessable()->assertJsonValidationErrors('grants');
        $this->postJson('/api/v1/access/roles', ['name' => 'boards', 'label_ar' => 'لوحات', 'grants' => ['tasks.view' => 'all']])
            ->assertUnprocessable();

        // Nor can HR strip from a role what HR could not have given.
        $finance = collect($this->getJson('/api/v1/access/roles')->json('data'))->firstWhere('name', 'finance_manager');
        $this->patchJson('/api/v1/access/roles/finance_manager', ['version' => $finance['version'], 'grants' => []])->assertUnprocessable();

        // Assigning: roles within reach yes; a role carrying more, or the admin role, no.
        $target = $this->user(['employee']);
        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['employee', 'hr_clerk']])->assertForbidden(); // needs users.manage too
        DB::table('usr_role_permissions')->insert([
            'role_id' => DB::table('usr_roles')->where('name', 'hr_manager')->value('id'),
            'permission_id' => DB::table('usr_permissions')->where('name', 'users.manage')->value('id'), 'scope' => 'all',
        ]);
        app(Access::class)->flush();
        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['employee', 'hr_clerk']])->assertOk();
        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => ['finance_manager']])->assertUnprocessable();
        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => [DefaultRoles::ADMIN]])->assertUnprocessable();
    }

    public function test_grants_follow_role_edits_on_the_next_request(): void
    {
        $engineer = $this->user(['engineer']);
        Sanctum::actingAs($engineer);
        $this->getJson('/api/v1/employees')->assertForbidden();

        $role = $this->role('engineer');
        $this->patchJson('/api/v1/access/roles/engineer', ['version' => $role['version'],
            'grants' => ['tasks.view' => 'own', 'employees.view' => 'all']])->assertOk();

        Sanctum::actingAs($engineer);
        $this->getJson('/api/v1/employees')->assertOk();
    }
}
