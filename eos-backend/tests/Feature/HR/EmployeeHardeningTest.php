<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** HR regressions found in the backend audit. */
class EmployeeHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function employee(string $no, ?int $userId = null): Employee
    {
        $employee = Employee::create(['employee_no' => $no, 'first_name' => 'Sami', 'father_name' => 'F', 'last_name' => 'Nour',
            'national_id' => $no, 'national_id_hash' => hash('sha256', $no), 'hire_date' => '2025-01-01', 'user_id' => $userId,
            'sham_cash_wallet' => '1111222233334444']);
        $employee->servicePeriods()->create(['start_date' => '2025-01-01']);

        return $employee;
    }

    public function test_hr_without_financial_access_cannot_redirect_a_salary_wallet(): void
    {
        $employee = $this->employee('HW-1');
        $this->as('hr_manager');

        $this->putJson("/api/v1/employees/{$employee->id}", ['sham_cash_wallet' => '9999888877776666'])
            ->assertUnprocessable()->assertJsonValidationErrors('sham_cash_wallet');
        $this->assertSame('1111222233334444', $employee->fresh()->sham_cash_wallet);
    }

    public function test_nobody_appoints_themselves_department_manager_beyond_their_grants(): void
    {
        $clerk = User::factory()->create();
        $clerk->assignRole('hr_manager');
        $self = $this->employee('HW-2', $clerk->id);
        $other = $this->employee('HW-3');
        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        Sanctum::actingAs($clerk, ['*']);

        $this->putJson("/api/v1/departments/{$department->id}", ['manager_employee_id' => $self->id])
            ->assertUnprocessable()->assertJsonValidationErrors('manager_employee_id');
        $this->putJson("/api/v1/departments/{$department->id}", ['manager_employee_id' => $other->id])->assertOk();
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'department.updated']);
    }

    public function test_terminating_a_manager_clears_the_department_link(): void
    {
        $manager = $this->employee('HW-4');
        $department = Department::create(['name' => 'Site', 'code' => 'SITE', 'manager_employee_id' => $manager->id]);
        $this->as('hr_manager');

        $this->postJson("/api/v1/employees/{$manager->id}/terminate", ['termination_date' => now()->toDateString(), 'reason' => 'Resigned'])
            ->assertOk();
        $this->assertNull($department->fresh()->manager_employee_id);
    }

    public function test_employee_list_rejects_array_filters_with_422(): void
    {
        $this->as('hr_manager');

        $this->getJson('/api/v1/employees?q[]=x')->assertUnprocessable();
    }
}
