<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function limitedManager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('users.manage');
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_limited_manager_cannot_assign_admin_role_on_user_creation(): void
    {
        $this->limitedManager();

        $this->postJson('/api/v1/users', [
            'name' => 'Test User',
            'email' => 'new-limited@test.example',
            'password' => 'StrongPass2026!',
            'roles' => ['system_admin'],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('usr_users', ['email' => 'new-limited@test.example']);
    }

    public function test_limited_manager_cannot_assign_admin_role_on_user_update(): void
    {
        $this->limitedManager();
        $target = User::factory()->create();

        $this->putJson("/api/v1/users/{$target->id}", [
            'roles' => ['system_admin'],
        ])->assertUnprocessable();

        $this->assertFalse($target->fresh()->hasRole('system_admin'));
    }

    public function test_limited_manager_cannot_disable_system_admin(): void
    {
        $this->limitedManager();
        $target = User::factory()->create();
        $target->assignRole('system_admin');

        $this->patchJson("/api/v1/users/{$target->id}/status", [
            'status' => 'disabled',
        ])->assertForbidden();

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_cannot_remove_role_of_last_admin_via_user_update(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);

        $this->putJson("/api/v1/users/{$admin->id}", [
            'roles' => ['employee'],
        ])->assertUnprocessable();

        $this->assertTrue($admin->fresh()->hasRole('system_admin'));
    }

    public function test_system_admin_can_assign_roles_on_creation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/users', [
            'name' => 'Engineer',
            'email' => 'new-engineer@test.example',
            'password' => 'StrongPass2026!',
            'roles' => ['engineer'],
        ])->assertCreated();

        $this->assertTrue(User::where('email', 'new-engineer@test.example')->firstOrFail()->hasRole('engineer'));
    }
}
