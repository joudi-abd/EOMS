<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmploymentContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function login(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    private function employee(string $no = 'U-11111111'): Employee
    {
        return Employee::create([
            'employee_no' => $no,
            'first_name' => 'Lina',
            'last_name' => 'Ali',
            'national_id' => $no,
            'national_id_hash' => hash('sha256', $no),
            'hire_date' => '2026-01-01',
        ]);
    }

    private function payload(string $number, string $start, string $end): array
    {
        return [
            'contract_number' => $number,
            'type' => 'fixed_term',
            'start_date' => $start,
            'end_date' => $end,
            'base_salary' => 900,
            'currency_code' => 'USD',
        ];
    }

    public function test_contract_is_created_as_draft_and_can_be_activated(): void
    {
        $this->login('hr_manager');
        $employee = $this->employee();
        $base = "/api/v1/employees/{$employee->id}/contracts";

        $id = $this->postJson($base, $this->payload('CT-1', '2026-01-01', '2026-12-31'))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson("{$base}/{$id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('hr_employment_contracts', ['id' => $id, 'status' => 'active']);
    }

    public function test_overlapping_contracts_cannot_both_be_activated(): void
    {
        $this->login('hr_manager');
        $employee = $this->employee();
        $base = "/api/v1/employees/{$employee->id}/contracts";

        $one = $this->postJson($base, $this->payload('CT-1', '2026-01-01', '2026-12-31'))->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$one}/activate")->assertOk();
        $two = $this->postJson($base, $this->payload('CT-2', '2026-06-01', '2027-06-01'))->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$two}/activate")->assertUnprocessable();
    }

    public function test_renewal_preserves_original_contract(): void
    {
        $this->login('hr_manager');
        $employee = $this->employee();
        $base = "/api/v1/employees/{$employee->id}/contracts";

        $original = $this->postJson($base, $this->payload('CT-1', '2026-01-01', '2026-12-31'))->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$original}/activate")->assertOk();

        $renewal = $this->postJson("{$base}/{$original}/renew", $this->payload('CT-2', '2027-01-01', '2027-12-31'))
            ->assertCreated()->assertJsonPath('data.renewed_from_id', $original)->json('data.id');

        $this->assertDatabaseHas('hr_employment_contracts', ['id' => $original, 'status' => 'active']);
        $this->assertDatabaseHas('hr_employment_contracts', ['id' => $renewal, 'status' => 'draft']);
        $this->postJson("{$base}/{$renewal}/activate")->assertOk();
    }

    public function test_employee_cannot_view_contracts(): void
    {
        $employee = $this->employee();
        $this->login('employee');
        $this->getJson("/api/v1/employees/{$employee->id}/contracts")->assertForbidden();
    }

    public function test_hr_manager_can_view_contract_but_not_salary(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $base = "/api/v1/employees/{$employee->id}/contracts";
        $id = $this->postJson($base, $this->payload('CT-1', '2026-01-01', '2026-12-31'))
            ->assertCreated()->json('data.id');

        $this->getJson("{$base}/{$id}")->assertOk()
            ->assertJsonMissingPath('data.base_salary')
            ->assertJsonMissingPath('data.currency_code');
    }

    public function test_contract_of_another_employee_is_not_accessible(): void
    {
        $this->login('hr_manager');
        $first = $this->employee();
        $second = $this->employee('U-22222222');
        $contract = EmploymentContract::create([
            'employee_id' => $first->id, 'contract_number' => 'CT-1',
            'type' => 'fixed_term', 'status' => 'draft',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);
        $this->getJson("/api/v1/employees/{$second->id}/contracts/{$contract->id}")->assertNotFound();
    }

    public function test_termination_keeps_contract_record(): void
    {
        $this->login('hr_manager');
        $employee = $this->employee();
        $base = "/api/v1/employees/{$employee->id}/contracts";
        $id = $this->postJson($base, $this->payload('CT-1', '2026-01-01', '2026-12-31'))
            ->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$id}/activate")->assertOk();
        $this->postJson("{$base}/{$id}/terminate", [
            'terminated_at' => '2026-10-01', 'reason' => 'Administrative decision',
        ])->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->assertDatabaseHas('hr_employment_contracts', ['id' => $id, 'status' => 'terminated']);
    }

    public function test_cannot_activate_contract_for_terminated_employee(): void
    {
        $this->login('hr_manager');

        $employee = $this->employee();

        $contract = EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'CT-TERMINATED-001',
            'type' => 'fixed_term',
            'status' => 'draft',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $employee->update([
            'status' => 'terminated',
            'termination_date' => '2026-10-01',
        ]);

        $this->postJson(
            "/api/v1/employees/{$employee->id}/contracts/{$contract->id}/activate"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee']);

        $this->assertSame(
            'draft',
            $contract->fresh()->status
        );
    }

    public function test_cannot_create_contract_for_terminated_employee(): void
    {
        $this->login('hr_manager');

        $employee = $this->employee();

        $employee->update([
            'status' => 'terminated',
            'termination_date' => '2026-10-01',
        ]);

        $this->postJson(
            "/api/v1/employees/{$employee->id}/contracts",
            $this->payload('CT-NEW-001', '2026-11-01', '2027-10-31')
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee']);

        $this->assertDatabaseMissing('hr_employment_contracts', [
            'contract_number' => 'CT-NEW-001',
        ]);
    }

    public function test_cannot_renew_contract_for_terminated_employee(): void
    {
        $this->login('hr_manager');

        $employee = $this->employee();

        $contract = EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'CT-OLD-001',
            'type' => 'fixed_term',
            'status' => 'expired',
            'start_date' => '2026-01-01',
            'end_date' => '2026-09-30',
        ]);

        $employee->update([
            'status' => 'terminated',
            'termination_date' => '2026-10-01',
        ]);

        $this->postJson(
            "/api/v1/employees/{$employee->id}/contracts/{$contract->id}/renew",
            $this->payload('CT-RENEW-001', '2026-11-01', '2027-10-31')
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee']);

        $this->assertSame('expired', $contract->fresh()->status);

        $this->assertDatabaseMissing('hr_employment_contracts', [
            'contract_number' => 'CT-RENEW-001',
        ]);
    }
}
