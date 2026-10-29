<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Database\Seeders\AttendancePermissionSeeder;
use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use App\Modules\HR\Services\Attendance\AttendanceDeviceService;
use App\Modules\HR\Services\Attendance\AttendanceImportService;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-02T18:00:00+03:00'));
    }

    private function employee(?User $user = null): Employee
    {
        $n = (string) ++$this->sequence;

        return Employee::create([
            'employee_no' => 'U-'.str_pad($n, 8, '0', STR_PAD_LEFT),
            'first_name' => 'Ali', 'father_name' => 'Omar', 'last_name' => 'Hassan',
            'national_id' => $n, 'national_id_hash' => hash('sha256', $n),
            'hire_date' => '2026-01-01', 'user_id' => $user?->id,
        ]);
    }

    private function actor(string $role = 'hr_manager'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function device(?Employee $employee = null, ?string $token = null): AttendanceDevice
    {
        $device = AttendanceDevice::create([
            'name' => 'Office', 'serial_number' => bin2hex(random_bytes(8)),
            'timezone' => 'Asia/Damascus', 'token_hash' => hash('sha256', $token ?? bin2hex(random_bytes(32))),
        ]);
        if ($employee) {
            AttendanceBinding::create(['device_id' => $device->id, 'employee_id' => $employee->id,
                'device_user_id' => '105', 'valid_from' => '2026-01-01']);
        }

        return $device;
    }

    private function events(AttendanceDevice $device, array $times, ?string $marker = null): array
    {
        $events = [];
        foreach ($times as $i => $time) {
            $events[] = ['external_id' => 'event-'.$i.'-'.$time, 'device_user_id' => '105',
                'occurred_at' => '2026-11-02T'.$time.':00+03:00', 'direction' => 'unknown'];
        }

        return app(AttendanceImportService::class)->import($device, $events, $marker);
    }

    private function calculate(Employee $employee, string $date = '2026-11-02'): AttendanceDay
    {
        return app(AttendanceCalculator::class)->calculate($employee, $date);
    }

    public function test_default_policy_matches_saturday_to_thursday_nine_to_four(): void
    {
        $p = AttendancePolicy::firstOrFail();
        $this->assertSame([0, 1, 2, 3, 4, 6], $p->working_days);
        $this->assertSame('09:00', $p->start_time);
        $this->assertSame('16:00', $p->end_time);
        $this->assertSame(45, $p->grace_minutes);
        $this->assertSame('completed_blocks', $p->grace_mode);
        $this->assertSame(60, $p->late_block_minutes);
        $this->assertSame('2.00', $p->overtime_multiplier);
        $this->assertSame('Asia/Damascus', $p->timezone);
    }

    public function test_normal_day_has_seven_hours_and_no_break_deduction(): void
    {
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00', '16:00'], '2026-11-02T16:00:00+03:00');
        $day = $this->calculate($employee);
        $this->assertSame('present', $day->status);
        $this->assertSame(420, $day->worked_minutes);
        $this->assertSame(0, $day->late_minutes);
        $this->assertTrue($day->coverage_complete);
        $this->assertSame(6, $day->first_in->hour); // Stored and returned as UTC.
    }

    public function test_grace_boundary_and_full_lateness_after_threshold(): void
    {
        AttendancePolicy::firstOrFail()->update(['grace_minutes' => 30, 'grace_mode' => 'threshold_full']);
        foreach (['09:29' => [29, 0], '09:30' => [30, 0], '09:31' => [31, 31]] as $time => [$actual, $charged]) {
            $employee = $this->employee();
            $device = $this->device($employee);
            $this->events($device, [$time, '16:00']);
            $day = $this->calculate($employee);
            $this->assertSame($actual, $day->late_actual_minutes);
            $this->assertSame($charged, $day->late_minutes);
        }
    }

    public function test_grace_can_use_excess_only_and_early_exit_is_separate(): void
    {
        AttendancePolicy::firstOrFail()->update(['grace_mode' => 'excess_only', 'grace_minutes' => 30]);
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:40', '15:45']);
        $day = $this->calculate($employee);
        $this->assertSame(40, $day->late_actual_minutes);
        $this->assertSame(10, $day->late_minutes);
        $this->assertSame(15, $day->early_minutes);
        $this->assertSame(365, $day->worked_minutes);
    }

    public function test_friday_is_day_off_and_saturday_is_working(): void
    {
        $employee = $this->employee();
        $this->assertSame('day_off', $this->calculate($employee, '2026-10-30')->status);
        $this->assertSame('absent_unconfirmed', $this->calculate($employee, '2026-10-31')->status);
    }

    public function test_single_punch_does_not_invent_checkout_or_worked_hours(): void
    {
        $employee = $this->employee();
        $this->events($this->device($employee), ['09:00']);
        $day = $this->calculate($employee);
        $this->assertSame('incomplete', $day->status);
        $this->assertNull($day->last_out);
        $this->assertNull($day->worked_minutes);
    }

    public function test_near_duplicate_punches_remain_raw_but_are_not_a_full_day(): void
    {
        $employee = $this->employee();
        $this->events($this->device($employee), ['09:00', '09:01']);
        $day = $this->calculate($employee);
        $this->assertSame('incomplete', $day->status);
        $this->assertContains('duplicate_punch_ignored', $day->flags);
        $this->assertDatabaseCount('hr_attendance_punches', 2);
    }

    public function test_multiple_punches_do_not_subtract_unrequested_breaks(): void
    {
        $employee = $this->employee();
        $this->events($this->device($employee), ['09:00', '12:00', '12:30', '16:00']);
        $day = $this->calculate($employee);
        $this->assertSame(420, $day->worked_minutes);
        $this->assertContains('multiple_punches_no_break_deduction', $day->flags);
    }

    public function test_missing_sync_never_confirms_absence_and_does_not_use_last_seen_as_coverage(): void
    {
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, []);
        $this->assertSame('absent_unconfirmed', $this->calculate($employee)->status);
        $this->assertNotNull($device->fresh()->last_seen_at);
        $this->events($device, [], '2026-11-02T16:00:00+03:00');
        $day = $this->calculate($employee);
        $this->assertSame('absent', $day->status);
        $this->assertSame(0, $day->worked_minutes);
    }

    public function test_open_day_remains_pending_before_finalization(): void
    {
        $employee = $this->employee();
        $this->device($employee);
        $this->travelTo(CarbonImmutable::parse('2026-11-02T17:59:00+03:00'));
        $this->assertSame('pending', $this->calculate($employee)->status);
    }

    public function test_before_hire_and_after_termination_do_not_count_as_absence(): void
    {
        $employee = $this->employee();
        $employee->update(['hire_date' => '2026-11-01', 'termination_date' => '2026-11-01', 'status' => 'terminated']);
        $this->assertSame('outside_service', $this->calculate($employee, '2026-10-31')->status);
        $this->assertSame('outside_service', $this->calculate($employee)->status);
    }

    public function test_import_is_idempotent_and_rejects_changed_payload_for_same_source_id(): void
    {
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00']);
        $result = $this->events($device, ['09:00']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $event = AttendancePunch::firstOrFail();
        $this->expectException(ValidationException::class);
        app(AttendanceImportService::class)->import($device, [[
            'external_id' => $event->external_id, 'device_user_id' => '105',
            'occurred_at' => '2026-11-02T09:05:00+03:00',
        ]], null);
    }

    public function test_conflicting_event_rolls_back_whole_batch_and_marker(): void
    {
        $device = $this->device();
        $this->events($device, ['09:00']);
        $id = AttendancePunch::firstOrFail()->external_id;
        try {
            app(AttendanceImportService::class)->import($device, [
                ['external_id' => 'new', 'device_user_id' => '105', 'occurred_at' => '2026-11-02T10:00:00+03:00'],
                ['external_id' => $id, 'device_user_id' => '105', 'occurred_at' => '2026-11-02T10:00:00+03:00'],
            ], '2026-11-02T16:00:00+03:00');
            $this->fail('Conflicting batch must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('hr_attendance_punches', 1);
            $this->assertNull($device->fresh()->complete_through);
        }
    }

    public function test_unknown_user_is_preserved_and_can_be_linked_later(): void
    {
        $device = $this->device();
        $this->events($device, ['09:00', '16:00']);
        $this->assertSame(2, AttendancePunch::whereNull('employee_id')->count());
        $employee = $this->employee();
        app(AttendanceDeviceService::class)->bind($device, [
            'employee_id' => $employee->id, 'device_user_id' => '105', 'valid_from' => '2026-01-01',
        ]);
        $this->assertSame(2, AttendancePunch::where('employee_id', $employee->id)->count());
        $this->assertSame('present', $this->calculate($employee)->status);
    }

    public function test_bindings_reject_overlap_and_keep_historical_ownership(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $device = $this->device($first);
        $this->events($device, ['09:00']);
        $this->expectException(ValidationException::class);
        try {
            app(AttendanceDeviceService::class)->bind($device, [
                'employee_id' => $second->id, 'device_user_id' => '105', 'valid_from' => '2026-11-01',
            ]);
        } finally {
            $this->assertSame($first->id, (int) AttendancePunch::firstOrFail()->employee_id);
        }
    }

    public function test_source_ids_are_scoped_to_device(): void
    {
        $this->events($this->device(), ['09:00']);
        $this->events($this->device(), ['09:00']);
        $this->assertDatabaseCount('hr_attendance_punches', 2);
    }

    public function test_device_endpoint_requires_token_and_rejects_disabled_device(): void
    {
        $token = str_repeat('a', 64);
        $device = $this->device(null, $token);
        $this->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertOk();
        $device->update(['is_active' => false]);
        $this->withToken($token)->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertUnauthorized();
    }

    public function test_bad_and_future_times_are_rejected_without_writing_events(): void
    {
        $token = str_repeat('b', 64);
        $this->device(null, $token);
        foreach (['2026-11-02T09:00:00', '2026-02-30T09:00:00+03:00', '2026-11-03T09:00:00+03:00'] as $at) {
            $this->withToken($token)->postJson('/api/v1/attendance/device-ingest', ['events' => [[
                'external_id' => 'x', 'device_user_id' => '105', 'occurred_at' => $at,
            ]]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('hr_attendance_punches', 0);
    }

    public function test_rotated_token_is_revoked_and_hash_is_never_returned(): void
    {
        $actor = $this->actor();
        $old = str_repeat('c', 64);
        $device = $this->device(null, $old);
        $response = $this->postJson('/api/v1/attendance/devices/'.$device->id.'/rotate-token')->assertOk();
        $response->assertJsonMissingPath('data.device.token_hash');
        $new = $response->json('data.token');
        $this->assertSame(64, strlen($new));
        $this->getJson('/api/v1/attendance/devices')->assertOk()->assertJsonMissingPath('data.0.token_hash');
        // Sanctum login does not replace the independent machine bearer token.
        $this->withToken($old)->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertUnauthorized();
        $this->withToken($new)->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertOk();
    }

    public function test_employee_can_only_view_own_attendance_and_cannot_manage_devices(): void
    {
        $user = $this->actor('employee');
        $own = $this->employee($user);
        $other = $this->employee();
        $this->getJson('/api/v1/employees/'.$own->id.'/attendance?date=2026-11-02')->assertOk();
        $this->getJson('/api/v1/employees/'.$other->id.'/attendance?date=2026-11-02')->assertForbidden();
        $this->getJson('/api/v1/attendance/days?date=2026-11-02')->assertForbidden();
        $this->getJson('/api/v1/attendance/devices')->assertForbidden();
        $otherDay = $this->calculate($other);
        $this->postJson('/api/v1/attendance/days/'.$otherDay->id.'/corrections', [])->assertForbidden();
    }

    public function test_correction_requires_different_reviewer_and_preserves_raw_punches(): void
    {
        $requester = $this->actor();
        $employee = $this->employee();
        $this->events($this->device($employee), ['09:00']);
        $day = $this->calculate($employee);
        $reviews = app(AttendanceReviewService::class);
        $correction = $reviews->request($day, [
            'version' => $day->version, 'type' => 'times', 'reason' => 'Forgot checkout',
            'first_in' => '2026-11-02T09:00:00+03:00', 'last_out' => '2026-11-02T16:00:00+03:00',
        ], $requester);
        $this->postJson('/api/v1/attendance/corrections/'.$correction->id.'/review', ['decision' => 'approve', 'reason' => 'Checked records'])->assertForbidden();
        $reviewer = $this->actor('company_manager');
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked records'], $reviewer);
        $day = $this->calculate($employee);
        $this->assertSame(420, $day->worked_minutes);
        $this->assertContains('manual_correction', $day->flags);
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $reviews->approve($day, $day->version, $reviewer);
        $this->assertNotNull($day->fresh()->approved_at);
    }

    public function test_stale_correction_cannot_be_approved_but_can_be_rejected(): void
    {
        $requester = $this->actor();
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00']);
        $day = $this->calculate($employee);
        $correction = app(AttendanceReviewService::class)->request($day, [
            'version' => $day->version, 'type' => 'absence', 'reason' => 'Manual review',
        ], $requester);
        $this->events($device, ['16:00']);
        $this->actor('company_manager');
        $url = '/api/v1/attendance/corrections/'.$correction->id.'/review';
        $this->postJson($url, ['decision' => 'approve', 'reason' => 'Checked records'])->assertConflict();
        $this->postJson($url, ['decision' => 'reject', 'reason' => 'New data arrived'])->assertOk();
    }

    public function test_approved_day_is_not_overwritten_when_late_punch_arrives(): void
    {
        $actor = $this->actor();
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00', '16:00'], '2026-11-02T16:00:00+03:00');
        $day = $this->calculate($employee);
        $reviews = app(AttendanceReviewService::class);
        $day = $reviews->approve($day, $day->version, $actor);
        $this->events($device, ['17:00']);
        $unchanged = $this->calculate($employee);
        $this->assertSame(420, $unchanged->worked_minutes);
        $this->assertTrue($unchanged->pending_changes);
        $day = $reviews->reopen($unchanged, $unchanged->version, 'Review late punch', $actor);
        $this->assertSame(480, $day->worked_minutes);
        $this->assertNull($day->approved_at);
        $this->assertFalse($day->pending_changes);
    }

    public function test_missing_sync_blocks_approval_and_version_protects_updates(): void
    {
        $this->actor();
        $employee = $this->employee();
        $this->events($this->device($employee), ['09:00', '16:00']);
        $day = $this->calculate($employee);
        $url = '/api/v1/attendance/days/'.$day->id.'/approve';
        $this->postJson($url, ['version' => $day->version])->assertConflict();
        $this->postJson($url, ['version' => $day->version + 2])->assertConflict();
    }

    public function test_policy_versions_are_future_only_and_do_not_rewrite_old_days(): void
    {
        $this->actor();
        $employee = $this->employee();
        $before = $this->calculate($employee);
        $policy = AttendancePolicy::firstOrFail()->only(['timezone', 'working_days', 'start_time', 'end_time',
            'grace_minutes', 'grace_mode', 'pairing_mode', 'duplicate_seconds', 'finalize_after_minutes']);
        $this->postJson('/api/v1/attendance/policies', $policy + ['effective_from' => '2026-11-02'])->assertUnprocessable();
        $policy['start_time'] = '08:00';
        $this->postJson('/api/v1/attendance/policies', $policy + ['effective_from' => '2026-11-03'])->assertCreated();
        $this->assertSame($before->policy_id, $this->calculate($employee)->policy_id);
        $this->assertNotSame($before->policy_id, $this->calculate($employee, '2026-11-03')->policy_id);
    }

    public function test_list_filters_and_summary_cover_all_results_not_only_current_page(): void
    {
        $this->actor();
        $first = $this->employee();
        $second = $this->employee();
        $this->events($this->device($first), ['09:00', '16:00']);
        $this->events($this->device($second), ['09:00', '16:00']);
        $this->getJson('/api/v1/attendance/days?date=2026-11-02&status=present&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.total', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_report_limits_range_and_distinguishes_unknown_hours(): void
    {
        $this->actor();
        $employee = $this->employee();
        $url = '/api/v1/employees/'.$employee->id.'/attendance-report';
        $this->getJson($url.'?from=2026-10-01&to=2026-11-02')->assertUnprocessable();
        $this->getJson($url.'?from=2026-11-02&to=2026-11-02')->assertOk()
            ->assertJsonPath('data.summary.unknown_worked_days', 1)
            ->assertJsonPath('data.summary.financial_effect_applied', false);
    }

    public function test_directional_mode_does_not_turn_unknown_or_out_only_punches_into_complete_day(): void
    {
        AttendancePolicy::firstOrFail()->update(['pairing_mode' => 'directional']);
        $employee = $this->employee();
        $device = $this->device($employee);
        app(AttendanceImportService::class)->import($device, [
            ['external_id' => 'one', 'device_user_id' => '105', 'occurred_at' => '2026-11-02T09:00:00+03:00', 'direction' => 'out'],
            ['external_id' => 'two', 'device_user_id' => '105', 'occurred_at' => '2026-11-02T16:00:00+03:00', 'direction' => 'unknown'],
        ], null);
        $day = $this->calculate($employee);
        $this->assertSame('incomplete', $day->status);
        $this->assertNull($day->worked_minutes);
        $this->assertContains('unclassified_punches', $day->flags);
    }

    public function test_calculation_is_stable_and_audit_failure_rolls_back_correction(): void
    {
        $actor = $this->actor();
        $employee = $this->employee();
        $day = $this->calculate($employee);
        $this->assertSame($day->version, $this->calculate($employee)->version);
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $reviews = new AttendanceReviewService(app(AttendanceCalculator::class), $audit);
        try {
            $reviews->request($day, ['version' => $day->version, 'type' => 'absence', 'reason' => 'Reviewed manually'], $actor);
            $this->fail('Audit failure should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
            $this->assertDatabaseCount('hr_attendance_corrections', 0);
        }
    }

    public function test_utc_storage_is_not_reinterpreted_in_server_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00', '16:00']);
        $this->assertSame(6, AttendancePunch::firstOrFail()->occurred_at->hour);
        $this->assertSame(420, $this->calculate($employee)->worked_minutes);
    }

    public function test_command_recalculates_requested_date_and_rejects_bad_date(): void
    {
        $this->employee();
        $this->artisan('attendance:recalculate', ['--date' => '2026-11-02'])->assertSuccessful();
        $this->assertDatabaseCount('hr_attendance_days', 1);
        $this->artisan('attendance:recalculate', ['--date' => 'not-a-date'])->assertFailed();
    }

    public function test_every_bound_device_must_confirm_coverage_even_when_disabled(): void
    {
        $employee = $this->employee();
        $first = $this->device($employee);
        $second = $this->device($employee);
        $this->events($first, [], '2026-11-02T16:00:00+03:00');
        $second->update(['is_active' => false]);
        $this->assertSame('absent_unconfirmed', $this->calculate($employee)->status);
        $second->update(['is_active' => true]);
        $this->events($second, [], '2026-11-02T16:00:00+03:00');
        $this->assertSame('absent', $this->calculate($employee)->status);
    }

    public function test_coverage_marker_is_monotonic_and_cannot_claim_future_sync(): void
    {
        $device = $this->device();
        $this->events($device, [], '2026-11-02T16:00:00+03:00');
        $this->events($device, [], '2026-11-02T15:00:00+03:00');
        $this->assertSame(13, $device->fresh()->complete_through->hour);
        $this->expectException(ValidationException::class);
        $this->events($device, [], '2026-11-02T18:01:00+03:00');
    }

    public function test_approved_present_day_does_not_gain_pending_changes_just_because_deadline_passes(): void
    {
        $actor = $this->actor();
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:00', '16:00'], '2026-11-02T16:00:00+03:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-02T16:30:00+03:00'));
        $day = $this->calculate($employee);
        app(AttendanceReviewService::class)->approve($day, $day->version, $actor);
        $this->travelTo(CarbonImmutable::parse('2026-11-02T18:30:00+03:00'));
        $this->assertFalse($this->calculate($employee)->pending_changes);
    }

    public function test_correction_endpoint_returns_pending_status_and_protects_duplicate_submission(): void
    {
        $user = $this->actor('employee');
        $employee = $this->employee($user);
        $day = $this->calculate($employee);
        $url = '/api/v1/attendance/days/'.$day->id.'/corrections';
        $payload = ['version' => $day->version, 'type' => 'times',
            'first_in' => '2026-11-02T09:00:00+03:00', 'last_out' => '2026-11-02T16:00:00+03:00',
            'reason' => 'Forgot attendance punches'];
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('hr_attendance_corrections', 1);
    }

    public function test_correction_rejects_cross_day_or_reversed_times(): void
    {
        $this->actor();
        $employee = $this->employee();
        $day = $this->calculate($employee);
        $url = '/api/v1/attendance/days/'.$day->id.'/corrections';
        foreach ([['2026-11-01T09:00:00+03:00', '2026-11-02T16:00:00+03:00'],
            ['2026-11-02T16:00:00+03:00', '2026-11-02T09:00:00+03:00']] as [$first, $last]) {
            $this->postJson($url, ['version' => $day->version, 'type' => 'times',
                'first_in' => $first, 'last_out' => $last, 'reason' => 'Review requested times'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('hr_attendance_corrections', 0);
    }

    public function test_close_and_reuse_binding_does_not_reassign_earlier_punches(): void
    {
        $first = $this->employee();
        $device = $this->device($first);
        $this->events($device, ['09:00']);
        $binding = AttendanceBinding::firstOrFail();
        $devices = app(AttendanceDeviceService::class);
        $devices->closeBinding($binding, '2026-11-02');
        $second = $this->employee();
        $devices->bind($device, ['employee_id' => $second->id, 'device_user_id' => '105', 'valid_from' => '2026-11-03']);
        $this->assertSame($first->id, (int) AttendancePunch::firstOrFail()->employee_id);
        $this->assertDatabaseCount('hr_attendance_bindings', 2);
    }

    public function test_permission_seeder_is_additive_and_repeatable(): void
    {
        $custom = Permission::firstOrCreate(['name' => 'custom.extra', 'guard_name' => 'web']);
        $role = Role::findByName('hr_manager', 'web');
        $role->givePermissionTo($custom);
        $this->seed(AttendancePermissionSeeder::class);
        $this->seed(AttendancePermissionSeeder::class);
        $this->assertTrue($role->fresh()->hasPermissionTo('custom.extra'));
        $this->assertTrue($role->fresh()->hasPermissionTo('attendance.approve'));
        $this->assertSame(1, Permission::where('name', 'attendance.approve')->count());
    }

    public function test_insecure_machine_request_is_rejected_in_production(): void
    {
        $token = str_repeat('d', 64);
        $this->device(null, $token);
        $this->app->detectEnvironment(fn () => 'production');
        $this->withToken($token)->postJson('/api/v1/attendance/device-ingest', ['events' => []])->assertForbidden();
        $this->withToken($token)->postJson('https://localhost/api/v1/attendance/device-ingest', ['events' => []])->assertOk();
    }

    public function test_complete_http_flow_from_device_creation_to_day_approval(): void
    {
        $this->actor();
        $employee = $this->employee();
        $created = $this->postJson('/api/v1/attendance/devices', [
            'name' => 'Main office', 'serial_number' => 'IFACE-HTTP-FLOW',
        ])->assertCreated()->assertJsonPath('data.device.is_active', true)
            ->assertJsonMissingPath('data.device.token_hash');
        $deviceId = $created->json('data.device.id');
        $token = $created->json('data.token');
        $this->postJson('/api/v1/attendance/devices/'.$deviceId.'/bindings', [
            'employee_id' => $employee->id, 'device_user_id' => '00105', 'valid_from' => '2026-01-01',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/attendance/device-ingest', [
            'events' => [
                ['external_id' => 'flow-in', 'device_user_id' => '00105', 'occurred_at' => '2026-11-02T09:00:00+03:00'],
                ['external_id' => 'flow-out', 'device_user_id' => '00105', 'occurred_at' => '2026-11-02T16:00:00+03:00'],
            ], 'complete_through' => '2026-11-02T16:00:00+03:00',
        ])->assertOk()->assertJsonPath('data.inserted', 2)->assertJsonPath('data.unmatched', 0);
        $detail = $this->getJson('/api/v1/employees/'.$employee->id.'/attendance?date=2026-11-02')
            ->assertOk()->assertJsonPath('data.day.worked_minutes', 420)->assertJsonCount(2, 'data.punches');
        $this->postJson('/api/v1/attendance/days/'.$detail->json('data.day.id').'/approve', [
            'version' => $detail->json('data.day.version'),
        ])->assertOk()->assertJsonPath('data.review_status', 'approved');
    }

    public function test_completed_hour_bands_match_hr_rule_in_actual_day_calculation(): void
    {
        foreach (['09:45' => [45, 0], '09:46' => [46, 60], '10:59' => [119, 60],
            '11:00' => [120, 120], '11:59' => [179, 120], '12:00' => [180, 180]] as $time => [$actual, $charged]) {
            $employee = $this->employee();
            $device = $this->device($employee);
            $this->events($device, [$time, '16:00']);
            $day = $this->calculate($employee);
            $this->assertSame($actual, $day->late_actual_minutes);
            $this->assertSame($charged, $day->late_minutes);
        }
    }

    public function test_hr_can_set_block_size_grace_and_future_overtime_multiplier(): void
    {
        $this->actor();
        $policy = AttendancePolicy::firstOrFail()->only(['timezone', 'working_days', 'start_time', 'end_time',
            'grace_minutes', 'grace_mode', 'pairing_mode', 'duplicate_seconds', 'finalize_after_minutes']);
        $payload = array_merge($policy, ['effective_from' => '2026-11-03', 'grace_minutes' => 15,
            'late_block_minutes' => 30, 'overtime_multiplier' => '2.50', 'grace_mode' => 'completed_blocks']);
        $this->postJson('/api/v1/attendance/policies', $payload)->assertCreated()
            ->assertJsonPath('data.late_block_minutes', 30)->assertJsonPath('data.overtime_multiplier', '2.50');
        $this->actor('employee');
        $payload['effective_from'] = '2026-11-04';
        $this->postJson('/api/v1/attendance/policies', $payload)->assertForbidden();
    }

    public function test_invalid_block_or_multiplier_is_rejected(): void
    {
        $this->actor();
        $policy = AttendancePolicy::firstOrFail()->only(['timezone', 'working_days', 'start_time', 'end_time',
            'grace_minutes', 'grace_mode', 'pairing_mode', 'duplicate_seconds', 'finalize_after_minutes']);
        foreach ([['late_block_minutes' => 0], ['late_block_minutes' => -1],
            ['overtime_multiplier' => '0.50'], ['overtime_multiplier' => '2.001']] as $invalid) {
            $this->postJson('/api/v1/attendance/policies', array_merge($policy, [
                'effective_from' => '2026-11-03', 'late_block_minutes' => 60, 'overtime_multiplier' => '2.00',
            ], $invalid))->assertUnprocessable();
        }
    }

    public function test_new_policy_does_not_reprice_approved_historical_day(): void
    {
        $actor = $this->actor();
        AttendancePolicy::firstOrFail()->update(['grace_minutes' => 30, 'grace_mode' => 'threshold_full']);
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['09:46', '16:00'], '2026-11-02T16:00:00+03:00');
        $day = $this->calculate($employee);
        $day = app(AttendanceReviewService::class)->approve($day, $day->version, $actor);
        $this->artisan('attendance:configure-defaults', ['--from' => '2026-11-03'])->assertSuccessful();
        $old = $this->calculate($employee);
        $this->assertSame(46, $old->late_minutes);
        $this->assertSame($day->policy_id, $old->policy_id);
        $this->assertFalse($old->pending_changes);
        $future = $this->calculate($employee, '2026-11-03');
        $this->assertSame('completed_blocks', AttendancePolicy::findOrFail($future->policy_id)->grace_mode);
    }

    public function test_defaults_command_refuses_past_and_duplicate_dates_without_changes(): void
    {
        $this->artisan('attendance:configure-defaults', ['--from' => '2026-11-02'])->assertFailed();
        $this->artisan('attendance:configure-defaults', ['--from' => '2026-02-30'])->assertFailed();
        $this->artisan('attendance:configure-defaults', ['--from' => '2026-11-03'])->assertSuccessful();
        $this->artisan('attendance:configure-defaults', ['--from' => '2026-11-03'])->assertFailed();
        $this->assertSame(1, AttendancePolicy::where('effective_from', '2026-11-03')->count());
    }

    public function test_day_resource_exposes_units_without_any_financial_deduction(): void
    {
        $this->actor();
        $employee = $this->employee();
        $device = $this->device($employee);
        $this->events($device, ['10:59', '17:00']);
        $this->getJson('/api/v1/employees/'.$employee->id.'/attendance?date=2026-11-02')->assertOk()
            ->assertJsonPath('data.day.late_actual_minutes', 119)->assertJsonPath('data.day.late_minutes', 60)
            ->assertJsonPath('data.day.late_hours', 1)->assertJsonPath('data.day.extra_minutes_unapproved', 60)
            ->assertJsonPath('data.day.policy.overtime_multiplier', '2.00');
        $this->getJson('/api/v1/employees/'.$employee->id.'/attendance-report?from=2026-11-02&to=2026-11-02')
            ->assertOk()->assertJsonPath('data.summary.financial_effect_applied', false);
        $this->getJson('/api/v1/attendance/devices')->assertOk()->assertJsonPath('data.0.model', 'iFace 880 Plus');
    }
}
