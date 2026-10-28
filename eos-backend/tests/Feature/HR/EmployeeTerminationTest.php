<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTerminationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_no' => 'U-44556677', 'first_name' => 'Ali', 'father_name' => 'Omar',
            'last_name' => 'Hassan', 'national_id' => '3344556677',
            'national_id_hash' => hash('sha256', '3344556677'), 'hire_date' => '2026-01-01',
        ]);
    }

    private function admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_termination_disables_linked_account_and_revokes_tokens(): void
    {
        $employee = $this->employee();
        $linked = User::factory()->create();
        $linked->assignRole('employee');
        $linked->createToken('laptop');
        $employee->update(['user_id' => $linked->id]);
        $this->admin();
        $this->postJson("/api/v1/employees/{$employee->id}/terminate", [
            'termination_date' => '2026-10-01', 'reason' => 'End of employment',
        ])->assertOk()->assertJsonPath('data.status', 'terminated');

        $this->assertSame('disabled', $linked->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('terminated', $employee->fresh()->status);
    }

    public function test_active_contract_must_be_settled_first(): void
    {
        $employee = $this->employee();
        EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'TERM-2026-01',
            'type' => 'fixed_term',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $this->admin();
        $this->postJson("/api/v1/employees/{$employee->id}/terminate", [
            'termination_date' => '2026-10-01', 'reason' => 'End of employment',
        ])->assertUnprocessable()->assertJsonValidationErrors('contracts');
        $this->assertSame('active', $employee->fresh()->status);
    }
}
