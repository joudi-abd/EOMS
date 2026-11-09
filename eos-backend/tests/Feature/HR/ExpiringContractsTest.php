<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpiringContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-09 09:00:00');
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function login(string $role = 'hr_manager'): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    private function contract(string $number, array $fields): EmploymentContract
    {
        $id = (string) random_int(100000000000, 999999999999);
        $employee = Employee::create([
            'employee_no' => 'U-'.substr($id, 0, 8),
            'first_name' => 'Lina',
            'last_name' => $number,
            'national_id' => $id,
            'national_id_hash' => hash('sha256', $id),
            'hire_date' => '2026-01-01',
        ]);

        return EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => $number,
            'type' => 'fixed_term',
            'status' => 'active',
            'start_date' => '2026-01-01',
            ...$fields,
        ]);
    }

    public function test_lists_active_contracts_ending_within_the_window_soonest_first(): void
    {
        $this->login();
        $this->contract('CT-LATER', ['end_date' => '2026-12-01']);
        $this->contract('CT-SOON', ['end_date' => '2026-11-20']);
        $this->contract('CT-SCHEDULED', ['end_date' => '2027-06-30', 'scheduled_termination_at' => '2026-11-15']);
        $this->contract('CT-FAR', ['end_date' => '2027-03-01']);
        $this->contract('CT-OPEN', ['end_date' => null]);
        $this->contract('CT-ENDED', ['end_date' => '2026-11-12', 'status' => 'expired']);

        $rows = $this->getJson('/api/v1/contracts/expiring')->assertOk()->json('data');

        $this->assertSame(['CT-SCHEDULED', 'CT-SOON', 'CT-LATER'], array_column($rows, 'contract_number'));
        $this->assertSame('scheduled_termination', $rows[0]['reason']);
        $this->assertSame('2026-11-15', $rows[0]['ends_on']);
        $this->assertSame(6, $rows[0]['days_left']);
        $this->assertArrayNotHasKey('base_salary', $rows[0]);
    }

    public function test_the_window_can_be_narrowed_and_needs_employee_access(): void
    {
        $this->contract('CT-SOON', ['end_date' => '2026-11-20']);
        $this->contract('CT-LATER', ['end_date' => '2026-12-01']);

        $this->login('employee');
        $this->getJson('/api/v1/contracts/expiring')->assertForbidden();

        $this->login();
        $this->assertCount(1, $this->getJson('/api/v1/contracts/expiring?days=14')->assertOk()->json('data'));
        $this->getJson('/api/v1/contracts/expiring?days=0')->assertUnprocessable();
    }
}
