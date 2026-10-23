<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('system_admin');

        return $user;
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'a@test.com',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@test.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'b@test.com',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'b@test.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_disabled_user_cannot_login(): void
    {
        User::factory()->disabled()->create([
            'email' => 'c@test.com',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'c@test.com',
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_employee_cannot_list_users(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');
        Sanctum::actingAs($employee, ['*']);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_admin_creates_user_and_action_is_audited(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/v1/users', [
            'name' => 'موظف جديد',
            'email' => 'new@test.com',
            'password' => 'Strong-Pass-2026',
            'roles' => ['engineer'],
        ])->assertCreated();

        $this->assertDatabaseHas('usr_users', ['email' => 'new@test.com']);
        $this->assertTrue(AuditLog::where('action', 'user.created')->exists());
    }

    public function test_password_is_never_written_to_audit_log(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $target = User::factory()->create();

        $this->patchJson("/api/v1/users/{$target->id}", [
            'password' => 'Another-Strong-Pass',
        ])->assertOk();

        $log = AuditLog::where('action', 'user.updated')->firstOrFail();
        $this->assertArrayNotHasKey('password', $log->old_values ?? []);
        $this->assertArrayNotHasKey('password', $log->new_values ?? []);
    }

    public function test_disabling_user_revokes_all_their_tokens(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $target = User::factory()->create();
        $target->createToken('phone');
        $target->createToken('laptop');

        $this->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'disabled'])
            ->assertOk();

        $this->assertSame('disabled', $target->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/users/{$admin->id}/status", ['status' => 'disabled'])
            ->assertUnprocessable();
    }

    public function test_cannot_remove_role_from_last_active_admin(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $this->putJson("/api/v1/users/{$admin->id}/roles", ['roles' => ['employee']])
            ->assertUnprocessable();
    }

}