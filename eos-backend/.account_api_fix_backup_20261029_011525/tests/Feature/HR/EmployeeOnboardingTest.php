<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function createEmployee(): Employee
    {
        $response = $this->postJson('/api/v1/employees', [
            'first_name' => 'Ali',
            'father_name' => 'Omar',
            'last_name' => 'Hassan',
            'national_id' => '10020304050',
            'hire_date' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.has_account', false);

        return Employee::findOrFail($response->json('data.id'));
    }

    public function test_employee_is_created_without_login(): void
    {
        $this->admin();
        $employee = $this->createEmployee();
        $this->assertNull($employee->user_id);
        $this->assertSame('Omar', $employee->father_name);
        $this->assertDatabaseCount('usr_users', 1); // only the admin
    }

    public function test_account_can_be_added_after_employee_creation_only_once(): void
    {
        $this->admin();
        $employee = $this->createEmployee();

        $this->postJson("/api/v1/employees/{$employee->id}/account", [
            'email' => 'ali@test.example',
            'password' => 'StrongPass2026!',
        ])->assertCreated()->assertJsonPath('data.has_account', true);

        $this->assertNotNull($employee->fresh()->user_id);
        $this->assertTrue($employee->fresh()->user->hasRole('employee'));

        $this->postJson("/api/v1/employees/{$employee->id}/account", [
            'email' => 'second@test.example',
            'password' => 'StrongPass2026!',
        ])->assertUnprocessable();
    }

    public function test_nested_account_cannot_be_sent_during_employee_creation(): void
    {
        $this->admin();
        $this->postJson('/api/v1/employees', [
            'first_name' => 'Ali', 'father_name' => 'Omar', 'last_name' => 'Hassan',
            'national_id' => '2233445566', 'hire_date' => '2026-01-01',
            'account' => ['email' => 'bad@test.example', 'password' => 'StrongPass2026!'],
        ])->assertUnprocessable()->assertJsonValidationErrors('account');
    }
}
