<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAccountSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_linked_employee_profile_cannot_be_edited_from_users_api(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');

        $account = User::factory()->create();

        $employee = Employee::create([
            'employee_no' => 'U-87654321',
            'user_id' => $account->id,
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'national_id' => '123456789012',
            'national_id_hash' => hash('sha256', '123456789012'),
            'hire_date' => '2026-10-01',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->putJson("/api/v1/users/{$account->id}", [
            'name' => 'Changed Name',
            'phone' => '0999999999',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone']);

        $this->assertSame('Ahmad', $employee->fresh()->first_name);
        $this->assertNotSame(
            'Changed Name',
            $account->fresh()->name
        );
    }
}
