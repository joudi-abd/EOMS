<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    private AttendanceDevice $device;

    private AttendanceBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00+03:00'));
        config(['attendance.adms.remote_enrollment' => true]);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('hr_manager');
        Sanctum::actingAs($this->actor);
        $this->employee = Employee::create(['employee_no' => 'AUDIT-001', 'first_name' => 'Ali',
            'last_name' => 'Hassan', 'national_id' => '987', 'national_id_hash' => hash('sha256', '987'),
            'hire_date' => '2026-01-01']);
        $this->device = AttendanceDevice::create(['name' => 'iFace', 'serial_number' => 'AUDIT',
            'timezone' => 'Asia/Damascus', 'token_hash' => hash('sha256', 'audit'),
            'connection_mode' => 'adms', 'adms_source_ip' => '127.0.0.1']);
        $this->binding = AttendanceBinding::create(['device_id' => $this->device->id,
            'employee_id' => $this->employee->id, 'device_user_id' => '00900002', 'valid_from' => '2026-01-01']);
    }

    private function start(): int
    {
        return $this->postJson('/api/v1/attendance/bindings/'.$this->binding->id.'/enroll-fingerprint',
            ['finger_id' => 4, 'confirm_user_update' => true])->assertCreated()->json('data.enrollment.id');
    }

    private function acknowledge(int $id): void
    {
        $gateway = app(AdmsGateway::class);
        foreach (['DATA', 'ENROLL_FP'] as $kind) {
            $gateway->poll($this->device);
            $command = DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('kind', $kind)->first();
            $gateway->acknowledge($this->device, [['ID' => (string) $command->id, 'CMD' => $kind, 'Return' => '0']]);
        }
    }

    public function test_own_identity_is_available_without_employee_directory_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        $this->employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.employee_id', $this->employee->id)
            ->assertJsonPath('data.user.employee.id', $this->employee->id)
            ->assertJsonMissingPath('data.user.employee.national_id')
            ->assertJsonMissingPath('data.user.employee.phone');
        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance?date=2026-11-07')->assertOk();
    }

    public function test_login_returns_own_employee_identity(): void
    {
        $user = User::factory()->create(['password' => 'password-for-test']);
        $user->assignRole('employee');
        $this->employee->update(['user_id' => $user->id]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-for-test'])
            ->assertOk()->assertJsonPath('data.user.employee_id', $this->employee->id);
    }

    public function test_current_binding_pin_is_not_treated_as_verified_fingerprint(): void
    {
        $this->employee->update(['biometric_code' => 123]);
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.fingerprint.status', 'linked')
            ->assertJsonPath('data.fingerprint.device_user_id', '00900002');
        $id = $this->start();
        $this->acknowledge($id);
        $this->getJson('/api/v1/employees/'.$this->employee->id)
            ->assertJsonPath('data.fingerprint.status', 'waiting');
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Verified physical device test'])->assertOk();
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('data.0.fingerprint.status', 'enrolled');
        $this->device->update(['is_active' => false]);
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertJsonPath('data.fingerprint.status', 'none');
    }

    public function test_latest_endpoint_resumes_after_deadline_and_cancellation_releases_device(): void
    {
        $id = $this->start();
        $this->acknowledge($id);
        $this->travel(20)->minutes();
        $this->getJson('/api/v1/attendance/bindings/'.$this->binding->id.'/fingerprint-enrollment')->assertOk()
            ->assertJsonPath('data.enrollment.id', $id)->assertJsonPath('data.can_confirm', true);
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/cancel', ['note' => 'Operator ended tracking'])
            ->assertOk()->assertJsonPath('data.enrollment.status', 'cancelled');
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Old confirmation cannot succeed'])->assertConflict();
        $this->start();
    }

    public function test_cancellation_waits_for_in_flight_command_and_ignores_late_ack(): void
    {
        $id = $this->start();
        app(AdmsGateway::class)->poll($this->device);
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/cancel', ['note' => 'Wait until timeout'])
            ->assertConflict();
        $this->travel(6)->minutes();
        $this->getJson('/api/v1/attendance/bindings/'.$this->binding->id.'/fingerprint-enrollment')
            ->assertOk()->assertJsonPath('data.can_confirm', false)->assertJsonPath('data.enrollment.status', 'timeout');
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/cancel', ['note' => 'Cancelled after timeout'])->assertOk();
        $command = DB::table('hr_adms_commands')->where('enrollment_id', $id)->first();
        app(AdmsGateway::class)->acknowledge($this->device, [['ID' => (string) $command->id, 'CMD' => 'DATA', 'Return' => '0']]);
        $this->assertDatabaseCount('hr_adms_commands', 1);
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'cancelled']);
    }

    public function test_cancel_retry_is_idempotent_and_new_routes_require_permission(): void
    {
        $id = $this->start();
        foreach (range(1, 2) as $i) {
            $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/cancel', ['note' => 'Operator cancelled task'])->assertOk();
        }
        $this->assertSame(1, DB::table('usr_audit_logs')->where('action', 'attendance.enrollment_cancelled')->count());
        $user = User::factory()->create();
        $user->assignRole('employee');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/attendance/bindings/'.$this->binding->id.'/fingerprint-enrollment')->assertForbidden();
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/cancel', ['note' => 'Unauthorized cancellation'])->assertForbidden();
    }

    public function test_cancel_audit_failure_rolls_back_enrollment_and_commands(): void
    {
        $id = $this->start();
        $this->mock(AuditLogger::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        try {
            app(AdmsGateway::class)->cancel($id, $this->actor->id, 'Operator cancelled task');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'queued_user']);
        $this->assertDatabaseHas('hr_adms_commands', ['enrollment_id' => $id, 'status' => 'queued']);
    }

    public function test_directory_hides_device_pin_from_roles_without_attendance_or_sensitive_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('project_manager');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.fingerprint.status', 'linked')
            ->assertJsonPath('data.fingerprint.device_user_id', null)
            ->assertJsonPath('data.fingerprint.bindings', []);
    }

    public function test_employee_summary_does_not_leave_expired_commands_in_a_queued_state(): void
    {
        $this->start();
        $this->travel(6)->minutes();
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.fingerprint.status', 'failed');
        $this->binding->update(['valid_to' => '2026-11-06']);
        $this->getJson('/api/v1/employees/'.$this->employee->id)->assertOk()
            ->assertJsonPath('data.fingerprint.status', 'none');
    }
}
