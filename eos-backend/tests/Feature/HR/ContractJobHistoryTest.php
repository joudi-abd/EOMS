<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\EmployeeJobHistoryService;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\HR\Services\EmploymentContractService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractJobHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00+03:00'));
        $this->actor = User::factory()->create();
        $this->actor->assignRole('hr_manager');
        Sanctum::actingAs($this->actor);
        $this->employee = Employee::create(['employee_no' => 'HISTORY-001', 'first_name' => 'Ali',
            'last_name' => 'Hassan', 'national_id' => '987', 'national_id_hash' => hash('sha256', '987'),
            'hire_date' => '2026-01-01']);
        app(EmployeeJobHistoryService::class)->recordHire($this->employee);
    }

    private function payload(string $number = 'CT-1', string $start = '2026-11-01', string $end = '2026-12-31'): array
    {
        return ['contract_number' => $number, 'type' => 'fixed_term', 'start_date' => $start, 'end_date' => $end];
    }

    private function create(): EmploymentContract
    {
        return app(EmploymentContractService::class)->create($this->employee, $this->payload());
    }

    private function active(): EmploymentContract
    {
        return app(EmploymentContractService::class)->activate($this->employee, $this->create());
    }

    public function test_contract_creation_activation_renewal_and_termination_appear_in_employee_timeline(): void
    {
        $base = '/api/v1/employees/'.$this->employee->id.'/contracts';
        $id = $this->postJson($base, $this->payload())->assertCreated()->json('data.id');
        $this->postJson($base.'/'.$id.'/activate')->assertOk();
        $next = $this->postJson($base.'/'.$id.'/renew', $this->payload('CT-2', '2027-01-01', '2027-12-31'))
            ->assertCreated()->json('data.id');
        $this->postJson($base.'/'.$id.'/terminate', ['terminated_at' => '2026-11-07', 'reason' => 'Mutual agreement'])->assertOk();
        $rows = $this->getJson('/api/v1/employees/'.$this->employee->id.'/job-history')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['hired', 'contract_created', 'contract_activated', 'contract_renewal_created', 'contract_terminated'], array_column($rows, 'event_type'));
        $renewal = collect($rows)->firstWhere('event_type', 'contract_renewal_created');
        $this->assertSame($next, $renewal['contract']['id']);
        $this->assertSame('CT-1', $renewal['contract']['previous_number']);
        $this->assertSame($id, $renewal['contract']['previous_id']);
        $this->assertSame($this->actor->id, $renewal['recorded_by']['id']);
        $terminated = collect($rows)->firstWhere('event_type', 'contract_terminated');
        $this->assertSame('Mutual agreement', $terminated['reason']);
        $this->assertSame('2026-11-07', $terminated['effective_date']);
        $this->assertNotNull($terminated['recorded_at']);
        $this->assertSame('active', $this->employee->refresh()->status);
        $this->assertSame('draft', EmploymentContract::findOrFail($next)->status);
    }

    public function test_schedule_cancel_and_reschedule_are_distinct_events_with_original_target_dates(): void
    {
        $service = app(EmploymentContractService::class);
        $contract = $this->active();
        foreach (['2026-11-20', '2026-11-25'] as $date) {
            $service->scheduleTermination($this->employee, $contract, ['terminated_at' => $date, 'reason' => 'Future ending']);
            $service->cancelScheduledTermination($this->employee, $contract);
        }
        $rows = EmployeeJobHistory::where('event_type', 'contract_end_cancelled')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-20', $rows[0]->contract_context['scheduled_for']);
        $this->assertSame('2026-11-25', $rows[1]->contract_context['scheduled_for']);
        $this->assertSame('2026-11-07', $rows[0]->effective_date->format('Y-m-d'));
        $this->assertSame('active', $contract->refresh()->status);
    }

    public function test_scheduled_execution_and_expiration_are_idempotent_and_have_no_human_actor(): void
    {
        $service = app(EmploymentContractService::class);
        $contract = $this->active();
        $service->scheduleTermination($this->employee, $contract, ['terminated_at' => '2026-11-08', 'reason' => 'Scheduled ending']);
        $this->travel(2)->days();
        $this->assertTrue($service->executeScheduledTermination($contract->id));
        $this->assertFalse($service->executeScheduledTermination($contract->id));
        $this->assertDatabaseHas('hr_employee_job_histories', ['contract_id' => $contract->id, 'event_type' => 'contract_terminated', 'recorded_by_user_id' => null, 'effective_date' => '2026-11-08']);
        $other = $service->create($this->employee, $this->payload('CT-2', '2026-11-09', '2026-11-10'));
        $service->activate($this->employee, $other);
        $this->travel(3)->days();
        $this->assertTrue($service->expireContract($other->id));
        $this->assertFalse($service->expireContract($other->id));
        $this->assertSame(1, EmployeeJobHistory::where('contract_id', $other->id)->where('event_type', 'contract_expired')->count());
        $this->assertDatabaseHas('hr_employee_job_histories', ['contract_id' => $other->id, 'event_type' => 'contract_expired', 'recorded_by_user_id' => null, 'effective_date' => '2026-11-10']);
    }

    public function test_failed_contract_validation_adds_no_history(): void
    {
        $contract = $this->create();
        $count = EmployeeJobHistory::count();
        try {
            app(EmploymentContractService::class)->terminate($this->employee, $contract, ['terminated_at' => '2026-11-07', 'reason' => 'Invalid draft termination']);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame($count, EmployeeJobHistory::count());
        }
    }

    public function test_audit_failure_rolls_back_both_contract_and_history(): void
    {
        $contract = $this->active();
        $count = EmployeeJobHistory::count();
        $this->mock(AuditLogger::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        try {
            app(EmploymentContractService::class)->terminate($this->employee, $contract, ['terminated_at' => '2026-11-07', 'reason' => 'Termination must roll back']);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame($count, EmployeeJobHistory::count());
        $this->assertSame('active', $contract->refresh()->status);
    }

    public function test_history_failure_rolls_back_contract_creation(): void
    {
        $this->mock(EmployeeJobHistoryService::class, fn ($mock) => $mock->shouldReceive('recordContract')->once()->andThrow(new \RuntimeException('History unavailable')));
        try {
            $this->create();
            $this->fail('Expected history failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('History unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('hr_employment_contracts', 0);
        $this->assertDatabaseCount('hr_employee_job_histories', 1);
    }

    public function test_future_contract_event_does_not_block_assignment_change_chronology(): void
    {
        $contract = app(EmploymentContractService::class)->create($this->employee, $this->payload('FUTURE', '2027-01-01', '2027-12-31'));
        app(EmploymentContractService::class)->activate($this->employee, $contract);
        // Removing the old manager is a real assignment change dated before the future contract.
        $manager = Employee::create(['employee_no' => 'MANAGER', 'first_name' => 'Manager', 'last_name' => 'One', 'national_id' => '888', 'national_id_hash' => hash('sha256', '888'), 'hire_date' => '2026-01-01']);
        $this->employee->update(['manager_id' => $manager->id]);
        app(EmployeeService::class)->update($this->employee, ['manager_id' => null, 'effective_date' => '2026-11-07', 'reason' => 'Manager reassignment']);
        $this->assertDatabaseHas('hr_employee_job_histories', ['event_type' => 'assignment_changed', 'effective_date' => '2026-11-07']);
    }

    public function test_history_snapshots_never_expose_financial_fields_or_another_employees_events(): void
    {
        $contract = $this->active();
        $contract->update(['base_salary' => '1500', 'currency_code' => 'USD', 'contract_number' => 'RENAMED']);
        $rows = $this->getJson('/api/v1/employees/'.$this->employee->id.'/job-history')->assertOk()->json('data');
        $created = collect($rows)->firstWhere('event_type', 'contract_created');
        $this->assertSame('CT-1', $created['contract']['number']);
        $this->assertArrayNotHasKey('base_salary', $created['contract']);
        $this->assertArrayNotHasKey('currency_code', $created['contract']);
        $other = Employee::create(['employee_no' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'One', 'national_id' => '999', 'national_id_hash' => hash('sha256', '999'), 'hire_date' => '2026-01-01']);
        $this->getJson('/api/v1/employees/'.$other->id.'/job-history')->assertOk()->assertJsonPath('data', []);
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/job-history')->assertForbidden();
    }

    public function test_employment_termination_is_distinct_from_contract_termination(): void
    {
        $contract = $this->active();
        app(EmploymentContractService::class)->terminate($this->employee, $contract, ['terminated_at' => '2026-11-07', 'reason' => 'Contract closed']);
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-11-07', 'reason' => 'Employment ended'], $this->actor);
        $this->assertDatabaseHas('hr_employee_job_histories', ['event_type' => 'employment_terminated', 'contract_id' => null, 'reason' => 'Employment ended']);
        $this->assertDatabaseHas('hr_employee_job_histories', ['event_type' => 'contract_terminated', 'contract_id' => $contract->id]);
    }

    public function test_rejected_activation_retry_does_not_duplicate_history(): void
    {
        $contract = $this->active();
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/contracts/'.$contract->id.'/activate')->assertUnprocessable();
        $this->assertSame(1, EmployeeJobHistory::where('contract_id', $contract->id)->where('event_type', 'contract_activated')->count());
    }

    public function test_employment_end_reason_stays_hidden_without_sensitive_permission(): void
    {
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-11-07', 'reason' => 'Confidential HR reason'], $this->actor);
        $user = User::factory()->create();
        $user->assignRole('project_manager');
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/employees/'.$this->employee->id.'/job-history')->assertOk();
        $this->assertSame(['hired'], array_column($response->json('data'), 'event_type'));
        $response->assertDontSee('Confidential HR reason');
    }
}
