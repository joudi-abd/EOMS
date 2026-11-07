<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/leave-settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
    }

    public function test_hr_can_publish_a_policy_and_activate_a_seeded_type(): void
    {
        $this->signInAs('hr_manager');
        $type = LeaveType::firstOrFail();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(4, 'data.types')
            ->assertJsonPath('data.types.0.active', false);

        $activation = self::URL.'/types/'.$type->id.'/activation';
        $this->patchJson($activation, ['active' => true])->assertUnprocessable();
        $url = self::URL.'/types/'.$type->id.'/policies';
        $policy = $this->postJson($url, $this->policy())->assertCreated();
        $this->patchJson($activation, ['active' => true])->assertOk()->assertJsonPath('data.active', true);

        $this->postJson($url, $this->policy())->assertUnprocessable();
        $this->postJson($url, [...$this->policy(), 'effective_from' => '2027-01-01', 'pay_percent' => 50])->assertCreated();
        $this->postJson($url, [...$this->policy(), 'effective_from' => '2027-02-01', 'year_start' => '04-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('year_basis');
        $this->assertDatabaseHas('hr_leave_policies', ['id' => $policy->json('data.id'), 'pay_percent' => 100]);
    }

    public function test_employee_and_company_manager_cannot_write_leave_settings(): void
    {
        foreach (['employee', 'company_manager'] as $role) {
            $this->signInAs($role);
            $this->postJson(self::URL.'/types', ['name' => 'New'])->assertForbidden();
            $this->postJson(self::URL.'/holidays', ['date' => '2026-12-01', 'name' => 'Office closure'])->assertForbidden();
        }
    }

    public function test_holiday_is_stored_once_per_date_and_can_be_removed(): void
    {
        $this->signInAs('hr_manager');
        $data = ['date' => '2026-12-01', 'name' => 'Office closure'];
        $holiday = $this->postJson(self::URL.'/holidays', $data)->assertCreated();
        $this->postJson(self::URL.'/holidays', $data)->assertUnprocessable();
        $this->deleteJson(self::URL.'/holidays/'.$holiday->json('data.id'))->assertOk();
        $this->assertDatabaseCount('hr_office_holidays', 0);
    }

    public function test_opening_balance_and_adjustments_are_bound_to_the_service_period(): void
    {
        $this->signInAs('hr_manager');
        $employee = Employee::create([
            'employee_no' => 'LEAVE-1', 'first_name' => 'Ali', 'last_name' => 'Hassan',
            'national_id' => '988', 'national_id_hash' => hash('sha256', '988'), 'hire_date' => '2025-01-01',
        ]);
        $period = $employee->servicePeriods()->create(['start_date' => '2025-01-01']);
        $type = LeaveType::firstOrFail();
        $this->postJson(self::URL.'/types/'.$type->id.'/policies', $this->policy())->assertCreated();
        $data = [
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'kind' => 'opening', 'days' => '2.25', 'reason' => 'Imported balance',
        ];

        $this->postJson(self::URL.'/balance-entries', $data)->assertCreated()
            ->assertJsonPath('data.service_period_id', $period->id)
            ->assertJsonPath('data.cycle_start', '2026-01-01')
            ->assertJsonPath('data.day_units', 2250000);
        $this->postJson(self::URL.'/balance-entries', $data)->assertUnprocessable();
        $this->postJson(self::URL.'/balance-entries', [...$data, 'kind' => 'adjustment', 'days' => '-3'])->assertUnprocessable();
        $this->postJson(self::URL.'/balance-entries', [...$data, 'kind' => 'adjustment', 'days' => '-0.25'])->assertCreated();
        $this->getJson(self::URL.'/balance-entries?employee_id='.$employee->id)->assertOk()->assertJsonCount(2, 'data');

        $period->update(['end_date' => '2026-10-31']);
        $newPeriod = $employee->servicePeriods()->create(['start_date' => '2026-11-02']);
        $this->postJson(self::URL.'/balance-entries', $data)->assertCreated()
            ->assertJsonPath('data.service_period_id', $newPeriod->id);
    }

    private function signInAs(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }

    private function policy(): array
    {
        return [
            'effective_from' => '2026-11-08', 'pay_percent' => 100,
            'annual_days' => '12', 'grant_method' => 'monthly', 'year_basis' => 'fixed',
            'year_start' => '01-01', 'first_year_method' => 'remaining', 'retroactive_days' => 0,
        ];
    }
}
