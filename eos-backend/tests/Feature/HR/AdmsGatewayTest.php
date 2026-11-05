<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\HR\Services\Attendance\AttendanceDeviceService;
use App\Modules\HR\Services\Attendance\AttendanceImportService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AdmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05T18:00:00+03:00'));
        config(['attendance.adms.enabled' => true, 'attendance.adms.allow_http' => true]);
        $this->device = AttendanceDevice::create(['name' => 'Office', 'serial_number' => 'IFACE_TEST',
            'timezone' => 'Asia/Damascus', 'token_hash' => hash('sha256', str_repeat('a', 64)),
            'connection_mode' => 'adms', 'adms_source_ip' => '127.0.0.1']);
    }

    private function actor(string $role = 'hr_manager'): User
    {
        $actor = User::factory()->create();
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function binding(): AttendanceBinding
    {
        $employee = Employee::create(['employee_no' => 'ADMS-001', 'first_name' => 'Ali',
            'father_name' => 'Omar', 'last_name' => 'Hassan', 'national_id' => '987',
            'national_id_hash' => hash('sha256', '987'), 'hire_date' => '2026-01-01']);

        return AttendanceBinding::create(['device_id' => $this->device->id, 'employee_id' => $employee->id,
            'device_user_id' => '00105', 'valid_from' => '2026-01-01']);
    }

    private function raw(string $body, string $endpoint = 'cdata?table=ATTLOG'): TestResponse
    {
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $this->call('POST', '/iclock/'.$endpoint.$separator.'SN=IFACE_TEST', [], [], [],
            ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '127.0.0.1'], $body);
    }

    private function begin(): int
    {
        config(['attendance.adms.remote_enrollment' => true]);
        $this->actor();
        $binding = $this->binding();
        $response = $this->postJson('/api/v1/attendance/bindings/'.$binding->id.'/enroll-fingerprint',
            ['finger_id' => 0, 'confirm_user_update' => true])->assertCreated();

        return $response->json('data.enrollment.id');
    }

    public function test_native_bootstrap_uses_root_route_and_requests_history(): void
    {
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertOk()
            ->assertSee('GET OPTION FROM: IFACE_TEST')->assertSee('ATTLOGStamp=0')->assertDontSee('9999');
        $this->assertNotNull($this->device->fresh()->last_seen_at);
        $this->assertNull($this->device->fresh()->complete_through);
        $this->get('/iclock/getrequest.aspx?SN=IFACE_TEST')->assertOk()->assertContent('OK');
    }

    public function test_gateway_is_closed_until_explicitly_enabled(): void
    {
        config(['attendance.adms.enabled' => false]);
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertStatus(503);
    }

    public function test_serial_source_ip_mode_and_device_status_are_required(): void
    {
        $this->get('/iclock/cdata?SN=other')->assertForbidden();
        $this->get('/iclock/cdata?SN[]=bad')->assertForbidden();
        $this->device->update(['adms_source_ip' => '10.42.0.61']);
        $this->withHeader('X-Forwarded-For', '10.42.0.61')->get('/iclock/cdata?SN=IFACE_TEST')->assertForbidden();
        $this->device->update(['adms_source_ip' => '127.0.0.1', 'is_active' => false]);
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertForbidden();
        $this->device->update(['is_active' => true, 'connection_mode' => 'bridge']);
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertForbidden();
        $this->assertDatabaseCount('hr_adms_uploads', 0);
    }

    public function test_plain_http_requires_explicit_private_network_opt_in(): void
    {
        config(['attendance.adms.allow_http' => false]);
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertForbidden();
        $this->get('https://localhost/iclock/cdata?SN=IFACE_TEST')->assertOk();
    }

    public function test_punches_and_original_body_commit_before_ack_and_keep_pin_zeros(): void
    {
        $binding = $this->binding();
        $raw = "00105\t2026-11-05 09:46:00\t0\t1\t0\t0\r\n00105\t2026-11-05 16:00:00\t1\t1\t0\t0\r\n";
        $this->raw($raw)->assertOk()->assertContent("OK: 2\n");
        $this->assertDatabaseHas('hr_adms_uploads', ['raw_body' => $raw, 'record_count' => 2]);
        $first = AttendancePunch::orderBy('id')->firstOrFail();
        $this->assertSame($binding->employee_id, $first->employee_id);
        $this->assertSame('00105', $first->device_user_id);
        $this->assertSame('2026-11-05 06:46:00', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('in', $first->direction);
        $this->assertSame('out', AttendancePunch::orderByDesc('id')->firstOrFail()->direction);
        $this->assertNull($this->device->fresh()->complete_through);
    }

    public function test_retries_different_batches_and_line_endings_do_not_duplicate_punches(): void
    {
        $first = "00105\t2026-11-05 09:00:00\t0\t1";
        $last = "00105\t2026-11-05 16:00:00\t1\t1";
        $this->raw($first."\r\n")->assertOk()->assertContent("OK: 1\n");
        $this->raw($first."\r\n")->assertOk();
        $this->raw($first."\n".$last."\n")->assertOk()->assertContent("OK: 2\n");
        $this->assertDatabaseCount('hr_attendance_punches', 2);
        $this->assertDatabaseCount('hr_adms_uploads', 2);
    }

    public function test_invalid_batch_is_not_partly_saved_or_acknowledged(): void
    {
        foreach (['bad record', "00105\t2026-02-30 09:00:00\t0\t1", "00105\t2026-11-05 09:00:00\tbad\t1",
            "00105\t2026-11-06 09:00:00\t0\t1", "00105\t2026-11-05 09:00:00\t0\t1\nBAD"] as $raw) {
            $this->raw($raw)->assertUnprocessable()->assertDontSee('OK:');
        }
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_import_storage_failure_rolls_back_archive_and_never_acks(): void
    {
        $importer = \Mockery::mock(AttendanceImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('DB unavailable'));
        $this->app->instance(AttendanceImportService::class, $importer);
        $this->raw("00105\t2026-11-05 09:00:00\t0\t1")->assertStatus(503)->assertContent('TEMPORARY ERROR');
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_unknown_user_is_retained_and_can_be_linked_later(): void
    {
        $this->raw("00105\t2026-11-05 09:00:00\t0\t1")->assertOk();
        $this->assertNull(AttendancePunch::firstOrFail()->employee_id);
        $binding = $this->binding();
        // Reuse the existing binding service to link only unknown historical records.
        $binding->delete();
        app(AttendanceDeviceService::class)->bind($this->device,
            ['employee_id' => $binding->employee_id, 'device_user_id' => '00105', 'valid_from' => '2026-01-01']);
        $this->assertSame($binding->employee_id, AttendancePunch::firstOrFail()->employee_id);
    }

    public function test_unknown_tables_and_biometric_templates_are_not_acknowledged_or_archived(): void
    {
        $this->raw('biometric template', 'cdata?table=FINGERTMP')->assertStatus(503);
        $this->raw('unknown', 'cdata?table=OPERLOG')->assertStatus(503);
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->raw('PushVersion=2.0.33S', 'cdata?table=options')->assertOk();
    }

    public function test_body_and_record_limits_are_enforced(): void
    {
        config(['attendance.adms.max_body_bytes' => 30]);
        $this->raw(str_repeat('x', 31))->assertStatus(413);
        config(['attendance.adms.max_body_bytes' => 1024, 'attendance.adms.max_records' => 1]);
        $this->raw("00105\t2026-11-05 09:00:00\t0\t1\n00105\t2026-11-05 16:00:00\t1\t1")->assertUnprocessable();
        $this->assertDatabaseCount('hr_adms_uploads', 0);
    }

    public function test_hr_configures_device_ip_and_old_json_token_cannot_bypass_adms(): void
    {
        $this->actor();
        $this->device->update(['connection_mode' => 'bridge', 'complete_through' => CarbonImmutable::now('UTC')]);
        $this->patchJson('/api/v1/attendance/devices/'.$this->device->id.'/connection',
            ['connection_mode' => 'adms', 'adms_source_ip' => '10.42.0.61'])->assertOk()
            ->assertJsonPath('data.adms_source_ip', '10.42.0.61')->assertJsonPath('data.complete_through', null);
        $this->withToken(str_repeat('a', 64))->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertUnauthorized();
    }

    public function test_connection_configuration_and_enrollment_require_hr_permission(): void
    {
        $this->actor('employee');
        $this->patchJson('/api/v1/attendance/devices/'.$this->device->id.'/connection',
            ['connection_mode' => 'adms', 'adms_source_ip' => '10.42.0.61'])->assertForbidden();
        $this->getJson('/api/v1/attendance/fingerprint-enrollments/1')->assertForbidden();
        $binding = $this->binding();
        $this->postJson('/api/v1/attendance/bindings/'.$binding->id.'/enroll-fingerprint',
            ['finger_id' => 0, 'confirm_user_update' => true])->assertForbidden();
    }

    public function test_connection_rejects_missing_or_invalid_ip(): void
    {
        $this->actor();
        foreach ([[], ['adms_source_ip' => 'http://10.42.0.61'], ['adms_source_ip' => '10.42.0.999']] as $fields) {
            $this->patchJson('/api/v1/attendance/devices/'.$this->device->id.'/connection',
                ['connection_mode' => 'adms'] + $fields)->assertUnprocessable();
        }
    }

    public function test_remote_enrollment_is_opt_in_and_requires_confirmation_of_user_update(): void
    {
        $this->actor();
        $binding = $this->binding();
        $url = '/api/v1/attendance/bindings/'.$binding->id.'/enroll-fingerprint';
        $this->postJson($url, ['finger_id' => 0, 'confirm_user_update' => true])->assertConflict();
        config(['attendance.adms.remote_enrollment' => true]);
        $this->postJson($url, ['finger_id' => 0])->assertUnprocessable();
        $this->postJson($url, ['finger_id' => 10, 'confirm_user_update' => true])->assertUnprocessable();
        $this->assertDatabaseCount('hr_adms_commands', 0);
    }

    public function test_enrollment_orders_user_then_fingerprint_and_ack_is_not_biometric_proof(): void
    {
        $id = $this->begin();
        $url = '/iclock/getrequest?SN=IFACE_TEST';
        $user = DB::table('hr_adms_commands')->first();
        $this->get($url)->assertOk()->assertContent("C:{$user->id}:{$user->payload}\n");
        $this->get($url)->assertContent('OK'); // no automatic retry of a potentially executed command
        $this->raw("ID={$user->id}&Return=0&CMD=ENROLL_FP", 'devicecmd')->assertOk();
        $this->assertDatabaseCount('hr_adms_commands', 1); // wrong command type
        $this->raw("ID={$user->id}&Return=0&CMD=DATA", 'devicecmd')->assertOk();
        $this->raw("ID={$user->id}&Return=0&CMD=DATA", 'devicecmd')->assertOk();
        $this->assertDatabaseCount('hr_adms_commands', 2);
        $fp = DB::table('hr_adms_commands')->where('kind', 'ENROLL_FP')->first();
        $this->get($url)->assertContent("C:{$fp->id}:ENROLL_FP PIN=00105\tFID=0\tRETRY=3\tOVERWRITE=0\n");
        $this->raw("ID={$fp->id}&Return=0&CMD=ENROLL_FP", 'devicecmd')->assertOk();
        $this->getJson('/api/v1/attendance/fingerprint-enrollments/'.$id)->assertOk()
            ->assertJsonPath('data.enrollment.status', 'awaiting_physical_verification')
            ->assertJsonPath('data.biometric_verified', false)->assertJsonPath('data.operator_confirmed', false);
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Witnessed a separate fingerprint recognition on the device'])->assertOk()
            ->assertJsonPath('data.enrollment.status', 'manually_confirmed')
            ->assertJsonPath('data.operator_confirmed', true)->assertJsonPath('data.biometric_verified', false);
    }

    public function test_failed_user_ack_does_not_queue_enroll(): void
    {
        $this->begin();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
        $cmd = DB::table('hr_adms_commands')->first();
        $this->raw("ID={$cmd->id}&Return=-1&CMD=DATA", 'devicecmd')->assertOk();
        $this->assertDatabaseCount('hr_adms_commands', 1);
        $this->assertDatabaseHas('hr_adms_enrollments', ['status' => 'failed']);
    }

    public function test_unpolled_commands_ignore_forged_ack_and_second_workflow_is_blocked(): void
    {
        $this->begin();
        $binding = AttendanceBinding::firstOrFail();
        $this->postJson('/api/v1/attendance/bindings/'.$binding->id.'/enroll-fingerprint',
            ['finger_id' => 1, 'confirm_user_update' => true])->assertConflict();
        $cmd = DB::table('hr_adms_commands')->first();
        $this->raw("ID={$cmd->id}&Return=0&CMD=DATA", 'devicecmd')->assertOk();
        $this->assertDatabaseCount('hr_adms_commands', 1);
        $this->assertDatabaseHas('hr_adms_commands', ['id' => $cmd->id, 'status' => 'queued']);
    }

    public function test_timeout_preserves_history_and_late_ack_cannot_trigger_enrollment(): void
    {
        $id = $this->begin();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
        $cmd = DB::table('hr_adms_commands')->first();
        $this->travel(6)->minutes();
        $this->raw("ID={$cmd->id}&Return=0&CMD=DATA", 'devicecmd')->assertOk();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertContent('OK');
        $this->getJson('/api/v1/attendance/fingerprint-enrollments/'.$id)->assertOk()
            ->assertJsonPath('data.enrollment.status', 'timeout');
        $this->assertDatabaseCount('hr_adms_commands', 1);
    }

    public function test_malformed_ack_and_early_manual_confirmation_do_not_change_state(): void
    {
        $id = $this->begin();
        $this->raw('ID[]=1&Return=0&CMD=DATA', 'devicecmd')->assertUnprocessable();
        $this->raw('ID=1&CMD=DATA', 'devicecmd')->assertUnprocessable();
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Manual test'])->assertConflict();
        $this->assertDatabaseHas('hr_adms_enrollments', ['status' => 'queued_user']);
    }

    public function test_disabled_device_stops_delivering_commands(): void
    {
        $this->begin();
        $this->device->update(['is_active' => false]);
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertForbidden();
        $this->assertDatabaseHas('hr_adms_commands', ['status' => 'queued']);
    }

    public function test_closed_binding_stops_queued_enrollment_and_connection_switch_is_blocked(): void
    {
        $this->begin();
        $this->patchJson('/api/v1/attendance/devices/'.$this->device->id.'/connection',
            ['connection_mode' => 'bridge'])->assertConflict();
        AttendanceBinding::query()->update(['valid_to' => '2026-11-04']);
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertContent('OK');
        $this->assertDatabaseHas('hr_adms_enrollments', ['status' => 'failed']);
    }

    public function test_audit_failure_rolls_back_queued_commands(): void
    {
        $actor = $this->actor();
        $binding = $this->binding();
        config(['attendance.adms.remote_enrollment' => true]);
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $gateway = new AdmsGateway(app(AttendanceImportService::class), $audit);
        try {
            $gateway->startEnrollment($binding, 0, $actor->id);
            $this->fail('Audit failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('hr_adms_commands', 0);
        $this->assertDatabaseCount('hr_adms_enrollments', 0);
    }

    public function test_setup_command_creates_device_and_updates_existing_ip_without_duplicates(): void
    {
        $this->artisan('attendance:configure-adms', ['serial' => 'NEW_SERIAL', 'ip' => '10.42.0.61'])->assertSuccessful();
        $this->assertDatabaseHas('hr_attendance_devices', ['serial_number' => 'NEW_SERIAL', 'adms_source_ip' => '10.42.0.61', 'connection_mode' => 'adms']);
        $this->artisan('attendance:configure-adms', ['serial' => 'NEW_SERIAL', 'ip' => '10.42.0.62'])->assertSuccessful();
        $this->assertSame(1, AttendanceDevice::where('serial_number', 'NEW_SERIAL')->count());
        $this->assertDatabaseHas('hr_attendance_devices', ['serial_number' => 'NEW_SERIAL', 'adms_source_ip' => '10.42.0.62']);
        $this->artisan('attendance:configure-adms', ['serial' => 'BAD_SERIAL', 'ip' => 'not-an-ip'])->assertFailed();
        $this->assertDatabaseMissing('hr_attendance_devices', ['serial_number' => 'BAD_SERIAL']);
    }

    private function diagnosticLog(): MockInterface
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once()->andReturnNull();
        Log::shouldReceive('channel')->with('adms')->once()->andReturn($logger);

        return $logger;
    }

    public function test_future_device_clock_is_logged_without_payload_and_does_not_ack(): void
    {
        config(['attendance.adms.diagnostics' => false]);
        $logger = $this->diagnosticLog();
        $this->raw("900001\t2026-11-06 05:41:00\t0\t1")->assertUnprocessable()->assertContent('INVALID DATA');
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(function ($context) {
            $encoded = json_encode($context);
            $this->assertStringNotContainsString('900001', $encoded);
            $this->assertStringNotContainsString('IFACE_TEST', $encoded);

            return $context['reason'] === 'future_event_time' && $context['http_status'] === 422
                && $context['table'] === 'ATTLOG' && $context['first_line_fields'] === 4;
        }));
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_record_format_rejection_is_logged_even_without_verbose_diagnostics(): void
    {
        config(['attendance.adms.diagnostics' => false]);
        $logger = $this->diagnosticLog();
        $this->raw('900001 2026-11-05 09:00:00 0 1')->assertUnprocessable();
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(fn ($context) => $context['reason'] === 'invalid_attlog_record' && $context['first_line_fields'] === 1));
    }

    public function test_success_diagnostics_report_counts_without_raw_data_or_query_secrets(): void
    {
        config(['attendance.adms.diagnostics' => true]);
        $logger = $this->diagnosticLog();
        $this->raw("900001\t2026-11-05 09:00:00\t0\t1", 'cdata?table=ATTLOG&token=SECRET_TOKEN')->assertOk()->assertContent("OK: 1\n");
        $logger->shouldHaveReceived('log')->with('info', 'adms.request', \Mockery::on(function ($context) {
            $encoded = json_encode($context);
            foreach (['900001', 'IFACE_TEST', 'SECRET_TOKEN', '09:00:00', 'raw_body'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }

            return $context['http_status'] === 200 && $context['accepted_records'] === 1
                && $context['reason'] === 'attlog_saved';
        }));
        $this->assertDatabaseCount('hr_attendance_punches', 1);
    }

    public function test_unsupported_upload_diagnostics_do_not_log_templates_or_unknown_table_names(): void
    {
        $logger = $this->diagnosticLog();
        $this->raw('PRIVATE_BIOMETRIC_TEMPLATE', 'cdata?table=SECRET_PERSON')->assertStatus(503);
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(function ($context) {
            $encoded = json_encode($context);
            $this->assertStringNotContainsString('PRIVATE_BIOMETRIC_TEMPLATE', $encoded);
            $this->assertStringNotContainsString('SECRET_PERSON', $encoded);

            return $context['table'] === 'OTHER' && $context['reason'] === 'unsupported_table';
        }));
    }

    public function test_ip_rejection_is_visible_before_controller_without_logging_serial(): void
    {
        $this->device->update(['adms_source_ip' => '10.42.0.61']);
        $logger = $this->diagnosticLog();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertForbidden();
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(fn ($context) => $context['http_status'] === 403 && $context['reason'] === 'source_ip_mismatch'
            && ! str_contains(json_encode($context), 'IFACE_TEST')));
    }

    public function test_options_diagnostics_distinguish_heartbeat_from_attendance_upload(): void
    {
        config(['attendance.adms.diagnostics' => true]);
        $logger = $this->diagnosticLog();
        $this->raw('PRIVATE_DEVICE_OPTIONS', 'cdata?table=options')->assertOk();
        $logger->shouldHaveReceived('log')->with('info', 'adms.request', \Mockery::on(fn ($context) => $context['table'] === 'OPTIONS' && $context['reason'] === 'options_received'
            && ! array_key_exists('accepted_records', $context)
            && ! str_contains(json_encode($context), 'PRIVATE_DEVICE_OPTIONS')));
        $this->assertDatabaseCount('hr_adms_uploads', 0);
    }

    public function test_logging_failure_does_not_change_a_committed_upload_ack(): void
    {
        config(['attendance.adms.diagnostics' => true]);
        Log::shouldReceive('channel')->with('adms')->once()->andThrow(new \RuntimeException('Log directory unavailable'));
        $this->raw("900001\t2026-11-05 09:00:00\t0\t1")->assertOk()->assertContent("OK: 1\n");
        $this->assertDatabaseCount('hr_adms_uploads', 1);
        $this->assertDatabaseCount('hr_attendance_punches', 1);
    }

    public function test_database_failure_diagnostics_do_not_dump_sql_bindings(): void
    {
        $logger = $this->diagnosticLog();
        $importer = \Mockery::mock(AttendanceImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new QueryException(
            'sqlite', 'INSERT PRIVATE_SQL', ['PRIVATE_BODY'], new \RuntimeException('PRIVATE_EXCEPTION')));
        $this->app->instance(AttendanceImportService::class, $importer);
        $this->raw("900001\t2026-11-05 09:00:00\t0\t1")->assertStatus(503);
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(fn ($context) => $context['reason'] === 'database_error' && ! str_contains(json_encode($context), 'PRIVATE')));
        $this->assertDatabaseCount('hr_adms_uploads', 0);
    }

    public function test_success_requests_do_not_log_when_verbose_diagnostics_are_disabled(): void
    {
        config(['attendance.adms.diagnostics' => false]);
        Log::shouldReceive('channel')->never();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
    }
}
