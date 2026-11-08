<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use App\Modules\HR\Services\Attendance\AttendanceDeviceService;
use App\Modules\HR\Services\Attendance\AttendanceImportService;
use App\Modules\HR\Services\EmployeeRehireService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\HR\Services\EmploymentContractService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeRehireTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $actor;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T18:00:00+03:00'));
        $this->actor = User::factory()->create();
        $this->actor->assignRole('hr_manager');
        Sanctum::actingAs($this->actor);
        $this->login = User::factory()->create();
        $this->login->assignRole('employee');
        $this->employee = Employee::create(['employee_no' => 'REHIRE-1', 'user_id' => $this->login->id,
            'first_name' => 'Ali', 'last_name' => 'Hassan', 'national_id' => '987',
            'national_id_hash' => hash('sha256', '987'), 'hire_date' => '2026-01-01']);
    }

    private function end(string $date = '2026-11-02'): void
    {
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => $date, 'reason' => 'Original termination'], $this->actor);
    }

    private function payload(string $date = '2026-11-07'): array
    {
        return ['effective_date' => $date, 'reason' => 'Approved return to work', 'confirm_new_service_period' => true];
    }

    private function rehire(string $date = '2026-11-07')
    {
        return $this->postJson('/api/v1/employees/'.$this->employee->id.'/rehire', $this->payload($date));
    }

    private function device(): AttendanceDevice
    {
        return AttendanceDevice::create(['name' => 'Office', 'serial_number' => 'REHIRE-DEVICE',
            'timezone' => 'Asia/Damascus', 'token_hash' => hash('sha256', 'test'),
            'connection_mode' => 'adms', 'adms_source_ip' => '127.0.0.1']);
    }

    public function test_return_preserves_identity_old_end_event_and_disabled_account(): void
    {
        $this->login->createToken('before termination');
        $this->end();
        $this->rehire()->assertOk()->assertJsonPath('data.id', $this->employee->id)
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.hire_date', '2026-01-01')
            ->assertJsonPath('data.current_service_start', '2026-11-07')
            ->assertJsonPath('data.service_periods.0.end_date', '2026-11-02')
            ->assertJsonPath('data.service_periods.1.end_date', null);
        $this->assertSame('REHIRE-1', $this->employee->refresh()->employee_no);
        $this->assertNull($this->employee->termination_date);
        $this->assertSame('disabled', $this->login->refresh()->status);
        $this->assertSame(0, $this->login->tokens()->count());
        $this->assertSame(['employee'], $this->login->getRoleNames()->all());
        $this->assertDatabaseHas('hr_employee_job_histories', ['event_type' => 'employment_terminated', 'reason' => 'Original termination']);
        $this->assertDatabaseHas('hr_employee_job_histories', ['event_type' => 'employment_rehired', 'effective_date' => '2026-11-07', 'recorded_by_user_id' => $this->actor->id]);
    }

    public function test_gap_is_outside_service_even_with_punches_and_old_approved_day_stays_unchanged(): void
    {
        $this->end();
        $calculator = app(AttendanceCalculator::class);
        $before = $calculator->calculate($this->employee, '2026-11-02');
        $before->update(['approved_at' => now()]);
        $oldSignature = $before->source_signature;
        $this->rehire()->assertOk();
        $device = $this->device();
        AttendancePunch::create(['device_id' => $device->id, 'employee_id' => $this->employee->id,
            'device_user_id' => '105', 'external_id' => 'old-gap-punch', 'occurred_at' => '2026-11-04T06:00:00Z', 'direction' => 'in', 'received_at' => now()]);
        $gap = $calculator->calculate($this->employee, '2026-11-04');
        $this->assertSame('outside_service', $gap->status);
        $this->assertNull($gap->worked_minutes);
        $this->assertNull($gap->late_minutes);
        $this->assertContains('punch_outside_service', $gap->flags);
        $this->assertNotSame('outside_service', $calculator->calculate($this->employee, '2026-11-07')->status);
        $preserved = $calculator->calculate($this->employee, '2026-11-02');
        $this->assertSame($oldSignature, $preserved->source_signature);
        $this->assertFalse($preserved->pending_changes);
    }

    public function test_repeat_return_is_rejected_without_duplicate_periods_or_events(): void
    {
        $this->end();
        $this->rehire()->assertOk();
        $this->rehire()->assertUnprocessable();
        $this->assertDatabaseCount('hr_employee_service_periods', 2);
        $this->assertSame(1, EmployeeJobHistory::where('event_type', 'employment_rehired')->count());
    }

    public function test_return_rejects_active_employee_same_end_day_future_date_and_missing_consent(): void
    {
        $this->rehire()->assertUnprocessable();
        $this->end();
        foreach (['2026-11-01', '2026-11-02', '2026-11-11'] as $date) {
            $this->rehire($date)->assertUnprocessable();
        }
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/rehire', ['effective_date' => '2026-11-07', 'reason' => '     '])->assertUnprocessable();
        $this->assertDatabaseCount('hr_employee_service_periods', 1);
        $this->assertDatabaseMissing('hr_employee_job_histories', ['event_type' => 'employment_rehired']);
    }

    public function test_multiple_service_cycles_and_second_termination_validate_current_start(): void
    {
        $this->end();
        $this->rehire()->assertOk();
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/terminate', ['termination_date' => '2026-11-06', 'reason' => 'Before return is invalid'])->assertUnprocessable();
        $this->end('2026-11-08');
        $this->rehire('2026-11-10')->assertOk();
        $this->assertDatabaseCount('hr_employee_service_periods', 3);
        $this->assertSame('outside_service', app(AttendanceCalculator::class)->calculate($this->employee, '2026-11-09')->status);
        $this->assertNotSame('outside_service', app(AttendanceCalculator::class)->calculate($this->employee, '2026-11-07')->status);
    }

    public function test_audit_failure_rolls_back_return_period_and_binding_revocation(): void
    {
        $this->end();
        $device = $this->device();
        $binding = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        $this->mock(AuditLogger::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        try {
            app(EmployeeRehireService::class)->rehire($this->employee, $this->payload(), $this->actor);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('terminated', $this->employee->refresh()->status);
        $this->assertDatabaseCount('hr_employee_service_periods', 1);
        $this->assertNull($binding->refresh()->revoked_from);
        $this->assertDatabaseMissing('hr_employee_job_histories', ['event_type' => 'employment_rehired']);
    }

    public function test_old_and_future_device_links_do_not_resume_and_explicit_new_link_is_allowed(): void
    {
        $device = $this->device();
        $old = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        $future = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '106', 'valid_from' => '2026-12-01']);
        $this->end();
        $this->rehire()->assertOk()->assertJsonPath('data.fingerprint.status', 'none');
        $this->assertSame('2026-11-03', $old->refresh()->revoked_from->format('Y-m-d'));
        $this->assertFalse($future->refresh()->isValidOn('2026-12-01'));
        $event = ['external_id' => 'return-punch', 'device_user_id' => '105', 'occurred_at' => '2026-11-07T06:00:00+00:00', 'direction' => 'in'];
        $result = app(AttendanceImportService::class)->import($device, [$event], null);
        $this->assertSame(1, $result['unmatched']);
        $new = app(AttendanceDeviceService::class)->bind($device, ['employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-11-07']);
        $this->assertNotSame($old->id, $new->id);
        $this->assertDatabaseHas('hr_attendance_punches', ['external_id' => 'return-punch', 'employee_id' => $this->employee->id]);
        $this->assertTrue($old->isValidOn('2026-11-02'));
        $event['external_id'] = 'new-link-punch';
        $event['occurred_at'] = '2026-11-07T13:00:00+00:00';
        $this->assertSame(0, app(AttendanceImportService::class)->import($device, [$event], null)['unmatched']);
    }

    public function test_old_pending_enrollment_cannot_be_confirmed_after_rehire(): void
    {
        config(['attendance.adms.remote_enrollment' => true]);
        $device = $this->device();
        $binding = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        $gateway = app(AdmsGateway::class);
        $id = $gateway->startEnrollment($binding, 0, $this->actor->id)['enrollment']->id;
        foreach (['DATA', 'ENROLL_FP'] as $kind) {
            $gateway->poll($device);
            $command = DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('kind', $kind)->first();
            $gateway->acknowledge($device, [['ID' => (string) $command->id, 'CMD' => $kind, 'Return' => '0']]);
        }
        $this->end();
        $this->rehire()->assertOk();
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm', ['physical_test_passed' => true, 'note' => 'Stale confirmation must fail'])->assertConflict();
        $new = app(AttendanceDeviceService::class)->bind($device, ['employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-11-07']);
        $this->assertNotNull($gateway->startEnrollment($new, 1, $this->actor->id)['enrollment']);
    }

    public function test_old_contract_drafts_are_not_reactivated_across_the_service_gap(): void
    {
        $service = app(EmploymentContractService::class);
        $draft = $service->create($this->employee, ['contract_number' => 'OLD', 'type' => 'fixed_term', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->end();
        $this->rehire()->assertOk();
        $base = '/api/v1/employees/'.$this->employee->id.'/contracts';
        $this->postJson($base.'/'.$draft->id.'/activate')->assertUnprocessable();
        $this->assertSame('draft', $draft->refresh()->status);
        $this->postJson($base, ['contract_number' => 'NEW', 'type' => 'fixed_term', 'start_date' => '2026-11-07', 'end_date' => '2027-11-06'])->assertCreated();
    }

    public function test_employee_role_cannot_rehire_and_sensitive_periods_stay_hidden(): void
    {
        $this->end();
        $this->rehire()->assertOk();
        $reader = User::factory()->create();
        $reader->assignRole('project_manager');
        Sanctum::actingAs($reader);
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()->assertJsonMissingPath('data.service_periods');
        $rows = $this->getJson('/api/v1/employees/'.$this->employee->id.'/job-history')->assertOk()->json('data');
        $this->assertNotContains('employment_rehired', array_column($rows, 'event_type'));
        Sanctum::actingAs($this->login);
        $this->rehire()->assertForbidden();
    }

    public function test_service_gap_is_not_reported_as_unknown_worked_days(): void
    {
        $this->end();
        $this->rehire()->assertOk();
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance-report?from=2026-11-03&to=2026-11-06')
            ->assertOk()->assertJsonPath('data.summary.by_status.outside_service', 4)
            ->assertJsonPath('data.summary.unknown_worked_days', 0)
            ->assertJsonPath('data.summary.late_minutes', 0);
    }

    public function test_migration_backfills_known_legacy_dates_without_inventing_a_return(): void
    {
        $migration = require app_path('Modules/HR/Database/Migrations/2026_11_07_025304_add_employee_service_periods_and_binding_revocation.php');
        // RefreshDatabase has already created the schema, including later tables with foreign keys.
        // The fixture was inserted after migration, so it is a genuine unbackfilled legacy row.
        $this->assertDatabaseCount('hr_employee_service_periods', 0);
        $transactionLevel = DB::connection()->transactionLevel();
        $this->employee->update(['status' => 'terminated', 'termination_date' => '2026-11-02', 'termination_reason' => 'Legacy end']);
        $migration->backfillKnownServiceDates();
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        $periods = EmployeeServicePeriod::where('employee_id', $this->employee->id)->get();
        $this->assertCount(1, $periods);
        $this->assertSame('2026-01-01', $periods[0]->start_date->format('Y-m-d'));
        $this->assertSame('2026-11-02', $periods[0]->end_date->format('Y-m-d'));
        $this->rehire()->assertOk();
    }

    public function test_approved_day_in_a_backdated_break_requires_review_instead_of_silent_rewrite(): void
    {
        $calculator = app(AttendanceCalculator::class);
        $day = $calculator->calculate($this->employee, '2026-11-04');
        $day->update(['approved_at' => now()]);
        $old = $day->status;
        $this->end();
        $this->rehire()->assertOk();
        $result = $calculator->calculate($this->employee, '2026-11-04');
        $this->assertSame($old, $result->status);
        $this->assertTrue($result->pending_changes);
    }

    public function test_return_uses_the_office_calendar_date_at_utc_midnight_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T22:30:00Z'));
        $this->end();
        $this->rehire('2026-11-07')->assertOk()->assertJsonPath('data.current_service_start', '2026-11-07');
    }

    public function test_retiring_an_in_flight_enrollment_keeps_device_busy_until_ack(): void
    {
        config(['attendance.adms.remote_enrollment' => true]);
        $device = $this->device();
        $binding = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        $gateway = app(AdmsGateway::class);
        $id = $gateway->startEnrollment($binding, 0, $this->actor->id)['enrollment']->id;
        $gateway->poll($device);
        $command = DB::table('hr_adms_commands')->where('enrollment_id', $id)->first();
        $this->end();
        $this->rehire()->assertOk();
        $new = app(AttendanceDeviceService::class)->bind($device, ['employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-11-07']);
        $url = '/api/v1/attendance/bindings/'.$new->id.'/enroll-fingerprint';
        $this->postJson($url, ['finger_id' => 1, 'confirm_user_update' => true])->assertConflict();
        $this->assertSame('OK', $gateway->poll($device));
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'await_user_ack']);
        $gateway->acknowledge($device, [['ID' => (string) $command->id, 'CMD' => 'DATA', 'Return' => '0']]);
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('hr_adms_commands', 1);
        $this->postJson($url, ['finger_id' => 1, 'confirm_user_update' => true])->assertCreated();
    }

    public function test_failed_termination_keeps_login_periods_and_pin_binding_intact(): void
    {
        $this->login->createToken('still valid');
        $device = $this->device();
        $binding = AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $this->employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        $this->mock(AuditLogger::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        try {
            $this->end();
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('active', $this->employee->refresh()->status);
        $this->assertSame('active', $this->login->refresh()->status);
        $this->assertSame(1, $this->login->tokens()->count());
        $this->assertNull($binding->refresh()->revoked_from);
        $this->assertDatabaseCount('hr_employee_service_periods', 0);
    }

    public function test_current_assignment_cannot_be_backdated_into_the_break(): void
    {
        $this->end();
        $this->rehire()->assertOk();
        $manager = Employee::create(['employee_no' => 'NEW-MANAGER', 'first_name' => 'Manager', 'last_name' => 'One', 'national_id' => '654', 'national_id_hash' => hash('sha256', '654'), 'hire_date' => '2026-01-01']);
        $url = '/api/v1/employees/'.$this->employee->id;
        $this->putJson($url, ['manager_id' => $manager->id, 'effective_date' => '2026-11-04', 'reason' => 'Gap assignment rejected'])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->putJson($url, ['manager_id' => $manager->id, 'effective_date' => '2026-11-07', 'reason' => 'New service assignment'])->assertOk();
    }

    public function test_legacy_end_reason_is_preserved_without_inventing_an_original_actor(): void
    {
        $this->employee->update(['status' => 'terminated', 'termination_date' => '2026-11-02', 'termination_reason' => 'Known legacy ending reason']);
        $this->rehire()->assertOk();
        $this->assertDatabaseHas('hr_employee_job_histories', ['employee_id' => $this->employee->id,
            'event_type' => 'employment_end_recorded', 'effective_date' => '2026-11-02',
            'reason' => 'Known legacy ending reason', 'recorded_by_user_id' => null]);
        $this->assertDatabaseHas('hr_employee_job_histories', ['employee_id' => $this->employee->id, 'event_type' => 'employment_rehired']);
        $this->assertNull($this->employee->refresh()->termination_reason);
    }
}
