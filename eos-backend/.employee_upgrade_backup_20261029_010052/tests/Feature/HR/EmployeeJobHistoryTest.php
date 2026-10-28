<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeJobHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function login(string $role = 'hr_manager'): User
    {
        $user = User::factory()->create();

        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function employee(
        string $employeeNo = 'U-12345678'
    ): Employee {
        $nationalId = str_replace('U-', '', $employeeNo);

        return Employee::create([
            'employee_no' => $employeeNo,
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'national_id' => $nationalId,
            'national_id_hash' => hash('sha256', $nationalId),
            'hire_date' => '2026-10-01',
        ]);
    }

    /**
     * Creating an employee records the initial assignment.
     */
    public function test_creating_employee_via_api_records_initial_assignment(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/employees', [
            'first_name' => 'Sara',
            'last_name' => 'Omar',
            'national_id' => '123456789000',
            'hire_date' => '2026-10-01',
            'account' => [
                'email' => 'history-sara@test.example',
                'password' => 'StrongPass2026!',
            ],
        ])->assertCreated();

        $employeeId = $response->json('data.id');

        $this->assertDatabaseHas('hr_employee_job_histories', [
            'employee_id' => $employeeId,
            'event_type' => 'hired',
            'effective_date' => '2026-10-01',
            'reason' => null,
        ]);

        $this->assertSame(
            1,
            EmployeeJobHistory::where('employee_id', $employeeId)->count()
        );
    }

    /**
     * Changing a manager creates only one job history record.
     */
    public function test_manager_change_is_recorded_once(): void
    {
        $this->login();

        $employee = $this->employee();
        $manager = $this->employee('U-87654321');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'تغيير المدير المباشر',
        ])->assertOk();

        $this->assertDatabaseCount('hr_employee_job_histories', 1);

        $this->assertDatabaseHas('hr_employee_job_histories', [
            'employee_id' => $employee->id,
            'event_type' => 'assignment_changed',
            'old_manager_id' => null,
            'new_manager_id' => $manager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'تغيير المدير المباشر',
        ]);

        // Sending the same manager again must not create another record.
        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
        ])->assertOk();

        $this->assertDatabaseCount('hr_employee_job_histories', 1);
    }

    /**
     * Personal information changes must not create job history.
     */
    public function test_address_change_does_not_create_job_history(): void
    {
        $this->login();

        $employee = $this->employee();

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'address' => 'Updated address',
        ])->assertOk();

        $this->assertDatabaseCount('hr_employee_job_histories', 0);
    }

    /**
     * Assignment changes require both effective date and reason.
     */
    public function test_assignment_change_requires_effective_date_and_reason(): void
    {
        $this->login();

        $employee = $this->employee();
        $manager = $this->employee('U-87654321');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'effective_date',
                'reason',
            ]);

        $this->assertDatabaseCount('hr_employee_job_histories', 0);

        $this->assertNull($employee->fresh()->manager_id);
    }

    /**
     * Assignment changes must store the supplied effective date and reason.
     */
    public function test_assignment_change_stores_effective_date_and_reason(): void
    {
        $this->login();

        $employee = $this->employee();
        $manager = $this->employee('U-87654321');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'نقل الموظف إلى إشراف مدير جديد',
        ])->assertOk();

        $this->assertDatabaseHas('hr_employee_job_histories', [
            'employee_id' => $employee->id,
            'event_type' => 'assignment_changed',
            'old_manager_id' => null,
            'new_manager_id' => $manager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'نقل الموظف إلى إشراف مدير جديد',
        ]);

        $this->assertSame(
            $manager->id,
            $employee->fresh()->manager_id
        );
    }

    /**
     * Effective date cannot be before the employee's hire date.
     */
    public function test_effective_date_cannot_precede_hire_date(): void
    {
        $this->login();

        $employee = $this->employee();
        $manager = $this->employee('U-87654321');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
            'effective_date' => '2026-09-01',
            'reason' => 'تغيير المدير المباشر',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_date']);

        $this->assertDatabaseCount('hr_employee_job_histories', 0);
    }

    /**
     * A new assignment cannot precede the latest recorded assignment.
     */
    public function test_cannot_record_assignment_before_latest_effective_date(): void
    {
        $this->login();

        $employee = $this->employee();

        $firstManager = $this->employee('U-87654321');
        $secondManager = $this->employee('U-11223344');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $firstManager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'تعيين مدير مباشر',
        ])->assertOk();

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $secondManager->id,
            'effective_date' => '2026-10-10',
            'reason' => 'تعديل بأثر رجعي',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_date']);

        $this->assertDatabaseCount('hr_employee_job_histories', 1);

        $this->assertSame(
            $firstManager->id,
            $employee->fresh()->manager_id
        );
    }

    /**
     * Effective date and reason cannot be sent without an actual assignment change.
     */
    public function test_metadata_without_assignment_change_is_rejected(): void
    {
        $this->login();

        $employee = $this->employee();

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'address' => 'New address',
            'effective_date' => '2026-10-20',
            'reason' => 'تعديل بيانات شخصية',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('hr_employee_job_histories', 0);
    }

    /**
     * Employees without permission cannot view job history.
     */
    public function test_employee_role_cannot_view_history(): void
    {
        $employee = $this->employee();

        $this->login('employee');

        $this->getJson("/api/v1/employees/{$employee->id}/job-history")
            ->assertForbidden();
    }

    /**
     * HR managers can view an employee's job history.
     */
    public function test_hr_manager_can_view_history(): void
    {
        $employee = $this->employee();

        $this->login('hr_manager');

        $this->getJson("/api/v1/employees/{$employee->id}/job-history")
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta',
                'links',
            ]);
    }

    /**
     * The history API returns the effective date and reason.
     */
    public function test_history_api_returns_reason_and_effective_date(): void
    {
        $this->login();

        $employee = $this->employee();
        $manager = $this->employee('U-87654321');

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'manager_id' => $manager->id,
            'effective_date' => '2026-10-20',
            'reason' => 'تغيير الهيكل الإداري',
        ])->assertOk();

        $this->getJson("/api/v1/employees/{$employee->id}/job-history")
            ->assertOk()
            ->assertJsonPath(
                'data.0.effective_date',
                '2026-10-20'
            )
            ->assertJsonPath(
                'data.0.reason',
                'تغيير الهيكل الإداري'
            )
            ->assertJsonPath('meta.total', 1);
    }
}
