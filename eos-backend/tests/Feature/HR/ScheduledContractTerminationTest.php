<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduledContractTerminationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-28 09:00:00');
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

    private function contract(): EmploymentContract
    {
        $employee = Employee::create([
            'employee_no' => 'U-11223344',
            'first_name' => 'Lina',
            'last_name' => 'Ali',
            'national_id' => '777444888111',
            'national_id_hash' => hash('sha256', '777444888111'),
            'hire_date' => '2026-01-01',
        ]);

        return EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'CT-SCHEDULED-001',
            'type' => 'fixed_term',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function path(EmploymentContract $contract): string
    {
        return "/api/v1/employees/{$contract->employee_id}/contracts/{$contract->id}";
    }

    public function test_future_termination_remains_active_until_scheduled_date(): void
    {
        $this->login();
        $contract = $this->contract();

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2026-11-30',
            'reason' => 'End by mutual agreement',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.scheduled_termination_at', '2026-11-30');

        Artisan::call('contracts:process-scheduled-terminations');
        $this->assertSame('active', $contract->fresh()->status);

        Date::setTestNow('2026-11-30 00:10:00');
        Artisan::call('contracts:process-scheduled-terminations');

        $contract->refresh();
        $this->assertSame('terminated', $contract->status);
        $this->assertSame('2026-11-30', $contract->terminated_at->format('Y-m-d'));
        $this->assertSame('End by mutual agreement', $contract->termination_reason);
        $this->assertNull($contract->scheduled_termination_at);

        Artisan::call('contracts:process-scheduled-terminations');
        $this->assertSame('terminated', $contract->fresh()->status);
    }

    public function test_scheduling_can_be_cancelled_before_due_date(): void
    {
        $this->login();
        $contract = $this->contract();

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2026-11-30', 'reason' => 'Planned end',
        ])->assertOk();

        $this->postJson($this->path($contract).'/cancel-scheduled-termination')
            ->assertOk()
            ->assertJsonPath('data.scheduled_termination_at', null);

        Date::setTestNow('2026-11-30');
        Artisan::call('contracts:process-scheduled-terminations');
        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_schedule_rejects_today_and_dates_after_contract_end(): void
    {
        $this->login();
        $contract = $this->contract();

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2026-10-28', 'reason' => 'Today',
        ])->assertUnprocessable();

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2027-01-02', 'reason' => 'Out of period',
        ])->assertUnprocessable();
    }

    public function test_unauthorized_user_cannot_schedule_termination(): void
    {
        $contract = $this->contract();
        $this->login('employee');

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2026-11-30', 'reason' => 'Unauthorized',
        ])->assertForbidden();
    }

    public function test_immediate_termination_clears_pending_schedule(): void
    {
        $this->login();
        $contract = $this->contract();

        $this->postJson($this->path($contract).'/schedule-termination', [
            'terminated_at' => '2026-11-30', 'reason' => 'Planned',
        ])->assertOk();

        $this->postJson($this->path($contract).'/terminate', [
            'terminated_at' => '2026-10-28', 'reason' => 'Immediate decision',
        ])->assertOk();

        $this->assertSame('terminated', $contract->fresh()->status);
        $this->assertNull($contract->fresh()->scheduled_termination_at);
    }
}
