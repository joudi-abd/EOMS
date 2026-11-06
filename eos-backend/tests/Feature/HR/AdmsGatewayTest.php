<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AdmsGateway;
use App\Modules\HR\Services\Attendance\AdmsQuarantineService;
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
        config([
            'attendance.adms.enabled' => true,
            'attendance.adms.allow_http' => true,
            'attendance.adms.remote_enrollment' => false,
        ]);
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
            "00105\t2026-11-05 09:00:00\t0\t1\nBAD"] as $raw) {
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

    public function test_future_device_clock_is_durably_quarantined_without_logging_payload(): void
    {
        config(['attendance.adms.diagnostics' => false]);
        $logger = $this->diagnosticLog();
        $this->raw("900001\t2026-11-06 05:41:00\t0\t1")->assertOk()->assertContent("OK: 1\n");
        $logger->shouldHaveReceived('log')->with('warning', 'adms.request', \Mockery::on(function ($context) {
            $encoded = json_encode($context);
            $this->assertStringNotContainsString('900001', $encoded);
            $this->assertStringNotContainsString('IFACE_TEST', $encoded);

            return $context['reason'] === 'attlog_saved_with_quarantine' && $context['http_status'] === 200
                && $context['quarantined_records'] === 1 && $context['imported_records'] === 0
                && $context['table'] === 'ATTLOG' && $context['first_line_fields'] === 4;
        }));
        $this->assertDatabaseCount('hr_adms_uploads', 1);
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

    public function test_handshake_sends_device_timezone_not_application_server_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->get('/iclock/cdata?SN=IFACE_TEST')->assertOk()->assertSee("TimeZone=3\n", false);
    }

    public function test_push_timezone_encoding_supports_fractional_negative_and_large_offsets(): void
    {
        foreach (['UTC' => 0, 'Asia/Kolkata' => 330, 'Asia/Kathmandu' => 345,
            'America/St_Johns' => -210, 'America/New_York' => -5, 'Pacific/Kiritimati' => 840] as $zone => $expected) {
            $this->device->update(['timezone' => $zone]);
            $this->get('/iclock/cdata?SN=IFACE_TEST')->assertOk()->assertSee("TimeZone={$expected}\n", false);
        }
    }

    private function heldRecord(): object
    {
        $this->raw("00105\t2026-11-06 05:41:00\t0\t1")->assertOk()->assertContent("OK: 1\n");

        return DB::table('hr_adms_quarantined_punches')->first();
    }

    public function test_mixed_batch_keeps_valid_punch_and_holds_future_record_before_ack(): void
    {
        $binding = $this->binding();
        $raw = "00105\t2026-11-05 17:59:00\t0\t1\n00105\t2026-11-06 05:41:00\t0\t1\n";
        $this->raw($raw)->assertOk()->assertContent("OK: 2\n");
        $this->assertDatabaseCount('hr_adms_uploads', 1);
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 1);
        $this->assertDatabaseHas('hr_adms_uploads', ['raw_body' => $raw, 'record_count' => 2]);
        $this->assertSame($binding->employee_id, AttendancePunch::firstOrFail()->employee_id);
        $this->assertDatabaseHas('hr_adms_quarantined_punches', ['status' => 'pending', 'reason' => 'future_event_time']);
        $this->assertNull($this->device->fresh()->complete_through);
    }

    public function test_quarantine_deduplicates_and_never_auto_imports_when_clock_catches_up(): void
    {
        $line = "00105\t2026-11-06 05:41:00\t0\t1";
        $this->raw($line."\r\n")->assertOk();
        $this->raw($line."\n")->assertOk();
        $this->travel(24)->hours();
        $this->raw($line."\n")->assertOk();
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 1);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
        $this->assertDatabaseHas('hr_adms_quarantined_punches', ['status' => 'pending']);
    }

    public function test_import_failure_rolls_back_valid_future_and_archive_together(): void
    {
        $importer = \Mockery::mock(AttendanceImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->app->instance(AttendanceImportService::class, $importer);
        $this->raw("00105\t2026-11-05 09:00:00\t0\t1\n00105\t2026-11-06 05:41:00\t0\t1")
            ->assertStatus(503)->assertContent('TEMPORARY ERROR');
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 0);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_quarantine_write_failure_never_acknowledges_or_commits_archive(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_starts_with(strtolower($query), 'insert') && str_contains($query, 'hr_adms_quarantined_punches')) {
                throw new \RuntimeException('Quarantine storage unavailable');
            }
        });
        $this->raw("00105\t2026-11-06 05:41:00\t0\t1")->assertStatus(503);
        $this->assertDatabaseCount('hr_adms_uploads', 0);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_quarantine_list_is_permission_protected_and_never_exposes_raw_archive(): void
    {
        $this->heldRecord();
        $this->actor('employee');
        $this->getJson('/api/v1/attendance/quarantined-punches')->assertForbidden();
        $this->actor();
        $this->getJson('/api/v1/attendance/quarantined-punches?device_id='.$this->device->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.device_user_id', '00105')
            ->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.occurred_at', '2026-11-06T02:41:00+00:00')
            ->assertJsonMissingPath('data.0.raw_body');
        $this->getJson('/api/v1/attendance/quarantined-punches?per_page=101')->assertUnprocessable();
    }

    public function test_hr_can_release_corrected_record_and_replay_does_not_restore_wrong_time(): void
    {
        $binding = $this->binding();
        $held = $this->heldRecord();
        $actor = $this->actor();
        $url = '/api/v1/attendance/quarantined-punches/'.$held->id.'/review';
        $body = ['action' => 'release', 'corrected_occurred_at' => '2026-11-05T17:30:00+03:00',
            'reason' => 'Verified the correct time with HR and the employee'];
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.reviewed_by', $actor->id);
        $punch = AttendancePunch::firstOrFail();
        $this->assertSame($binding->employee_id, $punch->employee_id);
        $this->assertSame('2026-11-05 14:30:00', $punch->occurred_at->format('Y-m-d H:i:s'));
        $this->raw("00105\t2026-11-06 05:41:00\t0\t1")->assertOk();
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 1);
        $this->postJson($url, $body)->assertConflict();
        $this->assertDatabaseHas('hr_adms_quarantined_punches', ['id' => $held->id,
            'occurred_at' => '2026-11-06 02:41:00', 'punch_id' => $punch->id]);
    }

    public function test_dismissal_preserves_original_and_replay_cannot_import_it(): void
    {
        $held = $this->heldRecord();
        $this->actor();
        $this->postJson('/api/v1/attendance/quarantined-punches/'.$held->id.'/review',
            ['action' => 'dismiss', 'reason' => 'Test event with an incorrect device clock'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->travel(24)->hours();
        $this->raw("00105\t2026-11-06 05:41:00\t0\t1")->assertOk();
        $this->assertDatabaseCount('hr_attendance_punches', 0);
        $this->assertDatabaseCount('hr_adms_uploads', 1);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 1);
    }

    public function test_release_requires_permission_reason_and_valid_nonfuture_explicit_time(): void
    {
        $held = $this->heldRecord();
        $url = '/api/v1/attendance/quarantined-punches/'.$held->id.'/review';
        $this->actor('employee');
        $this->postJson($url, ['action' => 'dismiss', 'reason' => 'Not authorized'])->assertForbidden();
        $this->actor();
        foreach ([['action' => 'release', 'reason' => 'Missing time'],
            ['action' => 'release', 'reason' => 'Invalid time', 'corrected_occurred_at' => '2026-11-05 09:00:00'],
            ['action' => 'release', 'reason' => 'Future time', 'corrected_occurred_at' => '2026-11-06T05:41:00+03:00'],
            ['action' => 'dismiss']] as $body) {
            $this->postJson($url, $body)->assertUnprocessable();
        }
        $this->assertDatabaseHas('hr_adms_quarantined_punches', ['id' => $held->id, 'status' => 'pending']);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_review_audit_failure_rolls_back_released_punch_and_review_state(): void
    {
        $held = $this->heldRecord();
        $actor = $this->actor();
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $service = new AdmsQuarantineService(app(AttendanceImportService::class), $audit);
        try {
            $service->review($held->id, ['action' => 'release', 'corrected_occurred_at' => '2026-11-05T17:30:00+03:00',
                'reason' => 'Verified manual correction'], $actor->id);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('hr_attendance_punches', 0);
        $this->assertDatabaseHas('hr_adms_quarantined_punches', ['id' => $held->id, 'status' => 'pending', 'punch_id' => null]);
    }

    public function test_new_valid_event_is_processed_while_older_future_event_stays_held(): void
    {
        $this->heldRecord();
        $this->raw("00105\t2026-11-06 05:41:00\t0\t1\n00105\t2026-11-05 17:59:00\t0\t1")
            ->assertOk()->assertContent("OK: 2\n");
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 1);
    }

    public function test_standard_json_bridge_import_still_rejects_future_events(): void
    {
        $this->device->update(['connection_mode' => 'bridge']);
        $this->withToken(str_repeat('a', 64))->postJson('/api/v1/attendance/device-ingest', [
            'events' => [['external_id' => 'future-bridge', 'device_user_id' => '00105',
                'occurred_at' => '2026-11-06T05:41:00+03:00']],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('hr_attendance_punches', 0);
        $this->assertDatabaseCount('hr_adms_quarantined_punches', 0);
    }

    private function acknowledgedEnrollment(): int
    {
        $id = $this->begin();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
        $user = DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('kind', 'DATA')->first();
        $this->raw("ID={$user->id}&Return=0&CMD=DATA", 'devicecmd')->assertOk();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
        $fp = DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('kind', 'ENROLL_FP')->first();
        $this->raw("ID={$fp->id}&Return=0&CMD=ENROLL_FP", 'devicecmd')->assertOk();

        return $id;
    }

    private function confirmEnrollment(int $id): TestResponse
    {
        return $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Witnessed successful fingerprint recognition under the original PIN']);
    }

    public function test_successful_device_ack_does_not_expire_while_waiting_for_human_confirmation(): void
    {
        $id = $this->acknowledgedEnrollment();
        $commands = DB::table('hr_adms_commands')->where('enrollment_id', $id)->orderBy('id')->get()->toJson();
        $this->travel(24)->hours();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertContent('OK');
        $this->getJson('/api/v1/attendance/fingerprint-enrollments/'.$id)->assertOk()
            ->assertJsonPath('data.enrollment.status', 'awaiting_physical_verification');
        $this->confirmEnrollment($id)->assertOk()->assertJsonPath('data.enrollment.status', 'manually_confirmed')
            ->assertJsonPath('data.operator_confirmed', true)->assertJsonPath('data.biometric_verified', false);
        $this->assertSame($commands, DB::table('hr_adms_commands')->where('enrollment_id', $id)->orderBy('id')->get()->toJson());
    }

    public function test_legacy_timeout_with_both_successful_acknowledgements_can_be_confirmed_without_resending(): void
    {
        $id = $this->acknowledgedEnrollment();
        DB::table('hr_adms_enrollments')->where('id', $id)->update(['status' => 'timeout']);
        $this->travel(10)->minutes();
        $this->confirmEnrollment($id)->assertOk()->assertJsonPath('data.enrollment.status', 'manually_confirmed');
        $this->assertDatabaseCount('hr_adms_commands', 2);
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertContent('OK');
    }

    public function test_true_timeout_without_fingerprint_ack_cannot_be_confirmed(): void
    {
        $id = $this->begin();
        $this->get('/iclock/getrequest?SN=IFACE_TEST')->assertOk();
        $this->travel(10)->minutes();
        $this->getJson('/api/v1/attendance/fingerprint-enrollments/'.$id)->assertOk()
            ->assertJsonPath('data.enrollment.status', 'timeout');
        $this->confirmEnrollment($id)->assertConflict();
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'timeout', 'confirmed_at' => null]);
    }

    public function test_claimed_verification_state_without_successful_command_history_is_not_confirmable(): void
    {
        $id = $this->begin();
        DB::table('hr_adms_enrollments')->where('id', $id)->update(['status' => 'awaiting_physical_verification']);
        $this->confirmEnrollment($id)->assertConflict();
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'confirmed_at' => null]);
    }

    public function test_success_acknowledged_after_original_command_deadline_is_not_recoverable(): void
    {
        $id = $this->acknowledgedEnrollment();
        $row = DB::table('hr_adms_enrollments')->find($id);
        DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('kind', 'ENROLL_FP')
            ->update(['acknowledged_at' => $row->expires_at]);
        DB::table('hr_adms_enrollments')->where('id', $id)->update(['status' => 'timeout']);
        $this->confirmEnrollment($id)->assertConflict();
    }

    public function test_old_timeout_cannot_confirm_over_a_newer_enrollment_for_the_same_pin(): void
    {
        $id = $this->acknowledgedEnrollment();
        DB::table('hr_adms_enrollments')->where('id', $id)->update(['status' => 'timeout']);
        $binding = AttendanceBinding::firstOrFail();
        $this->postJson('/api/v1/attendance/bindings/'.$binding->id.'/enroll-fingerprint',
            ['finger_id' => 1, 'confirm_user_update' => true])->assertCreated();
        $this->confirmEnrollment($id)->assertConflict();
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id, 'status' => 'timeout']);
    }

    public function test_confirmation_requires_original_binding_to_still_be_active(): void
    {
        $id = $this->acknowledgedEnrollment();
        AttendanceBinding::query()->update(['valid_to' => '2026-11-04']);
        $this->confirmEnrollment($id)->assertConflict();
    }

    public function test_confirmation_audit_failure_preserves_successful_commands_and_pending_confirmation(): void
    {
        $id = $this->acknowledgedEnrollment();
        $this->travel(10)->minutes();
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $gateway = new AdmsGateway(app(AttendanceImportService::class), $audit);
        try {
            $gateway->confirm($id, (int) auth()->id(), 'Witnessed successful physical verification');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseHas('hr_adms_enrollments', ['id' => $id,
            'status' => 'awaiting_physical_verification', 'confirmed_at' => null]);
        $this->assertSame(2, DB::table('hr_adms_commands')->where('enrollment_id', $id)->where('status', 'acknowledged')->count());
    }

    public function test_confirmation_retry_returns_saved_success_without_duplicate_audit_or_commands(): void
    {
        $id = $this->acknowledgedEnrollment();
        $first = $this->confirmEnrollment($id)->assertOk()->json();
        $saved = DB::table('hr_adms_enrollments')->find($id);
        $commandSnapshot = DB::table('hr_adms_commands')->orderBy('id')->get()->toJson();
        $this->travel(10)->minutes();
        $second = $this->confirmEnrollment($id)->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertEquals($saved, DB::table('hr_adms_enrollments')->find($id));
        $this->assertSame($commandSnapshot, DB::table('hr_adms_commands')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('usr_audit_logs')->where('action', 'attendance.enrollment_manually_confirmed')->count());
    }

    public function test_another_authorized_actor_retry_does_not_overwrite_original_confirmation(): void
    {
        $id = $this->acknowledgedEnrollment();
        $this->confirmEnrollment($id)->assertOk();
        $saved = DB::table('hr_adms_enrollments')->find($id);
        $other = $this->actor();
        $this->postJson('/api/v1/attendance/fingerprint-enrollments/'.$id.'/confirm',
            ['physical_test_passed' => true, 'note' => 'Different note on a repeated request'])->assertOk()
            ->assertJsonPath('data.enrollment.confirmed_by', $saved->confirmed_by)
            ->assertJsonPath('data.enrollment.confirmation_note', $saved->confirmation_note);
        $this->assertNotSame($saved->confirmed_by, $other->id);
        $this->assertEquals($saved, DB::table('hr_adms_enrollments')->find($id));
        $this->assertSame(1, DB::table('usr_audit_logs')->where('action', 'attendance.enrollment_manually_confirmed')->count());
    }

    public function test_confirmed_receipt_can_be_retried_after_device_disabled_or_binding_closed(): void
    {
        $id = $this->acknowledgedEnrollment();
        $this->confirmEnrollment($id)->assertOk();
        $saved = DB::table('hr_adms_enrollments')->find($id);
        $this->device->update(['is_active' => false, 'connection_mode' => 'bridge']);
        AttendanceBinding::query()->update(['valid_to' => '2026-11-04']);
        $this->confirmEnrollment($id)->assertOk()->assertJsonPath('data.enrollment.status', 'manually_confirmed');
        $this->assertEquals($saved, DB::table('hr_adms_enrollments')->find($id));
        $this->actor('employee');
        $this->confirmEnrollment($id)->assertForbidden();
    }
}
