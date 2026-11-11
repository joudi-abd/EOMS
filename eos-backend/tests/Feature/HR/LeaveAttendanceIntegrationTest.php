<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\HR\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

class LeaveAttendanceIntegrationTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase;

    public function test_full_day_leave_is_not_absence_and_needs_no_device_sync_to_approve(): void
    {
        $pending = $this->leave(['start_date' => '2026-11-03']);
        // A pending request changes nothing, but is visible to whoever reviews the day.
        $day = $this->calculate('2026-11-03');
        $this->assertSame('absent_unconfirmed', $day->status);
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance?date=2026-11-03')->assertOk()
            ->assertJsonPath('data.day.pending_leave_request_id', $pending['id'])
            ->assertJsonPath('data.day.leave', null);

        $this->leaveAction($pending, 'approve', $this->hr);
        $day = AttendanceDay::where('employee_id', $this->employee->id)->where('work_date', '2026-11-03')->firstOrFail();
        $this->assertSame('on_leave', $day->status);
        $this->assertSame(0, $day->worked_minutes);
        $this->assertSame(0, $day->required_minutes);
        $this->assertSame(420, $day->leave_minutes);
        $this->assertNull($day->late_minutes);
        $this->assertNotContains('sync_unconfirmed', $day->flags);
        $approved = app(AttendanceReviewService::class)->approve($day, $day->version, $this->hr);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_punch_on_full_leave_day_stays_leave_and_is_listed_as_conflict(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03']);
        $this->punches('2026-11-03', ['09:05', '15:50'], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-03');
        $this->assertSame('on_leave', $day->status);
        $this->assertContains('punch_on_leave_day', $day->flags);
        $this->assertSame(2, AttendancePunch::where('employee_id', $this->employee->id)->count());

        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/attendance/days?date=2026-11-03&flag=punch_on_leave_day')->assertOk()
            ->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.status', 'on_leave');
        $this->getJson('/api/v1/attendance/days?date=2026-11-03&flag=work_on_holiday')->assertOk()
            ->assertJsonPath('summary.total', 0);
    }

    public function test_full_day_leave_stays_full_after_shift_hours_change(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03']);
        $leaveDay = LeaveRequestDay::where('employee_id', $this->employee->id)->where('date', '2026-11-03')->sole();
        $this->assertSame(1_000_000, (int) $leaveDay->day_units);
        $this->assertSame(['09:00', '16:00'], [$leaveDay->start_time, $leaveDay->end_time]);

        $base = AttendancePolicy::query()->orderBy('effective_from')->firstOrFail();
        AttendancePolicy::create([
            'effective_from' => '2026-11-02', 'timezone' => $base->timezone, 'working_days' => $base->working_days,
            'start_time' => '09:00', 'end_time' => '17:00', 'grace_minutes' => $base->grace_minutes,
            'grace_mode' => $base->grace_mode, 'late_block_minutes' => $base->late_block_minutes,
            'overtime_multiplier' => $base->overtime_multiplier, 'pairing_mode' => $base->pairing_mode,
            'duplicate_seconds' => $base->duplicate_seconds, 'finalize_after_minutes' => $base->finalize_after_minutes,
        ]);

        $day = $this->calculate('2026-11-03');
        $this->assertSame('on_leave', $day->status);
        $this->assertSame(480, $day->leave_minutes);
        $this->assertSame(0, $day->required_minutes);
        $this->assertNotContains('partial_leave', $day->flags);

        // Hourly leave keeps its wall-clock window and stays partial under the longer day.
        $this->approvedLeave(['start_date' => '2026-11-02', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-02', ['09:00', '17:00'], '2026-11-02T17:00:00');
        $partial = $this->calculate('2026-11-02');
        $this->assertSame('present', $partial->status);
        $this->assertContains('partial_leave', $partial->flags);
        $this->assertSame(120, $partial->leave_minutes);
        $this->assertSame(360, $partial->required_minutes);
        $this->assertSame(360, $partial->worked_minutes);
    }

    public function test_mid_day_hourly_leave_is_removed_from_required_and_worked_time(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-03', ['09:00', '16:00'], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-03');
        $this->assertSame('present', $day->status);
        $this->assertSame(300, $day->worked_minutes);
        $this->assertSame(300, $day->required_minutes);
        $this->assertSame(120, $day->leave_minutes);
        $this->assertSame(0, $day->late_minutes);
        $this->assertSame(0, $day->early_minutes);
        $this->assertContains('partial_leave', $day->flags);
        $this->assertNotContains('punch_during_leave', $day->flags);
    }

    public function test_leave_at_day_edges_moves_lateness_and_early_exit_reference(): void
    {
        // Leave 09:00–11:00: the required start becomes 11:00, so a 12:00 arrival is one hour late.
        $this->approvedLeave(['start_date' => '2026-11-03', 'unit' => 'hours', 'start_time' => '09:00', 'end_time' => '11:00']);
        // Leave 14:00–16:00: leaving at 14:00 is not an early exit.
        $this->approvedLeave(['start_date' => '2026-11-02', 'unit' => 'hours', 'start_time' => '14:00', 'end_time' => '16:00']);
        $this->punches('2026-11-03', ['12:00', '16:00'], '2026-11-03T16:00:00');
        $this->punches('2026-11-02', ['09:00', '14:00']);

        $late = $this->calculate('2026-11-03');
        $this->assertSame('present', $late->status);
        $this->assertSame(60, $late->late_actual_minutes);
        $this->assertSame(60, $late->late_minutes); // 45-minute grace, completed one-hour blocks.
        $this->assertSame(240, $late->worked_minutes);

        $early = $this->calculate('2026-11-02');
        $this->assertSame(0, $early->early_minutes);
        $this->assertSame(0, $early->late_minutes);
        $this->assertSame(300, $early->worked_minutes);
        $this->assertSame(0, $early->extra_minutes);
    }

    public function test_absence_on_the_rest_of_a_partial_leave_day_is_still_absence(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03', 'unit' => 'half', 'half' => 'first']);
        $this->punches('2026-11-03', [], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-03');
        $this->assertSame('absent', $day->status);
        $this->assertSame(210, $day->required_minutes);
        $this->assertContains('partial_leave', $day->flags);
    }

    public function test_retroactive_leave_on_approved_attendance_day_creates_pending_change(): void
    {
        $this->punches('2026-11-03', [], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-03');
        $this->assertSame('absent', $day->status);
        $reviews = app(AttendanceReviewService::class);
        $day = $reviews->approve($day, $day->version, $this->hr);

        $this->approvedLeave(['start_date' => '2026-11-03']);
        $day->refresh();
        $this->assertSame('absent', $day->status, 'An approved day is never rewritten silently.');
        $this->assertTrue($day->pending_changes);

        $day = $reviews->reopen($day, $day->version, 'Leave approved retroactively', $this->hr);
        $this->assertSame('on_leave', $day->status);
        $this->assertFalse($day->pending_changes);
    }

    public function test_holiday_is_not_absence_and_calendar_changes_refresh_existing_days(): void
    {
        $this->punches('2026-11-03', [], '2026-11-03T16:00:00');
        $draft = $this->calculate('2026-11-03');
        $approved = $this->calculate('2026-11-02');
        $approved = app(AttendanceReviewService::class)->approve($approved, $approved->version, $this->hr);
        $this->assertSame('absent', $draft->status);

        Sanctum::actingAs($this->hr);
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-03', 'name' => 'Office closed'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-02', 'name' => 'Office closed'])->assertCreated();
        $this->assertSame('holiday', $draft->refresh()->status);
        $this->assertNotContains('sync_unconfirmed', $draft->flags);
        $this->assertSame('absent', $approved->refresh()->status);
        $this->assertTrue($approved->pending_changes);
        $this->getJson('/api/v1/attendance/days/'.$draft->id)->assertOk()
            ->assertJsonPath('data.day.holiday.name', 'Office closed');

        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertSuccessful();
        $this->assertSame('absent', $draft->refresh()->status);
    }

    public function test_holiday_added_over_approved_leave_refunds_leave_and_day_becomes_holiday(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-05']);
        $day = $this->calculate('2026-11-05');
        $this->assertSame('on_leave', $day->status);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-05', 'name' => 'Feast'])->assertCreated();
        $day->refresh();
        $this->assertSame('holiday', $day->status);
        $this->assertNull($day->leave_request_day_id);
    }

    public function test_cancelling_future_approved_leave_restores_the_attendance_day(): void
    {
        $this->assertSame('pending', $this->calculate('2026-11-05')->status);
        $row = $this->approvedLeave(['start_date' => '2026-11-05']);
        $day = AttendanceDay::where('employee_id', $this->employee->id)->where('work_date', '2026-11-05')->firstOrFail();
        $this->assertSame('on_leave', $day->status);

        $row = $this->leaveAction($row, 'cancel', $this->employeeUser);
        $this->assertSame('on_leave', $day->refresh()->status, 'A cancellation request alone changes nothing.');
        $this->leaveAction($row, 'approve-change', $this->hr);
        $day->refresh();
        $this->assertSame('pending', $day->status);
        $this->assertNull($day->leave_request_day_id);
    }

    public function test_unpaid_portion_is_still_leave_and_pay_split_is_exposed(): void
    {
        $this->policy->update(['annual_days' => 0]);
        $this->approvedLeave(['start_date' => '2026-11-03'], consent: true);
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance?date=2026-11-03')->assertOk()
            ->assertJsonPath('data.day.status', 'on_leave')
            ->assertJsonPath('data.day.leave.full_day', true)
            ->assertJsonPath('data.day.leave.covered_units', 0)
            ->assertJsonPath('data.day.leave.unpaid_units', 1000000)
            ->assertJsonPath('data.day.leave.leave_type.id', $this->type->id)
            ->assertJsonPath('data.day.leave.still_active', true);
    }

    public function test_leave_day_cannot_be_overridden_by_an_attendance_correction(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03']);
        $day = $this->calculate('2026-11-03');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/days/'.$day->id.'/corrections', ['version' => $day->version,
            'type' => 'times', 'first_in' => '2026-11-03T09:00:00+03:00', 'last_out' => '2026-11-03T16:00:00+03:00',
            'reason' => 'Came in after all'])->assertUnprocessable();
    }

    public function test_ending_service_releases_leave_after_the_end_date_and_attendance_follows(): void
    {
        $periodId = $this->employee->servicePeriods()->value('id');
        $this->calculate('2026-11-08');
        $approved = $this->approvedLeave(['start_date' => '2026-11-05', 'end_date' => '2026-11-08']);
        $pending = $this->leave(['start_date' => '2026-11-10']);
        $this->assertSame('on_leave', AttendanceDay::where('work_date', '2026-11-08')->value('status'));

        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-11-05', 'reason' => 'Resigned'], $this->hr);

        $kept = LeaveRequestDay::where('leave_request_id', $approved['id'])->where('active', true)->get();
        $this->assertSame(['2026-11-05'], $kept->map(fn ($day) => substr((string) $day->date, 0, 10))->all());
        $this->assertSame('approved', LeaveRequest::findOrFail($approved['id'])->status);
        $this->assertSame('cancelled', LeaveRequest::findOrFail($pending['id'])->status);
        // Only the day still inside the service period keeps consuming balance.
        $this->assertSame(1_000_000, (int) $kept->sum('covered_units'));
        $this->assertSame(9_000_000, app(LeaveBalanceService::class)->available($periodId, $this->type->id, '2026-01-01'));
        $this->assertSame('outside_service', AttendanceDay::where('work_date', '2026-11-08')->value('status'));
    }

    public function test_payroll_inputs_expose_leave_pay_split_and_block_unreviewed_days(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03']);
        $this->approvedLeave(['start_date' => '2026-11-02', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->leave(['start_date' => '2026-11-10']);
        $this->punches('2026-11-02', ['09:00', '16:00'], '2026-11-03T16:00:00');
        $finance = $this->actor('finance_manager');

        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/payroll-inputs?month=2026-11')->assertForbidden();
        Sanctum::actingAs($finance);
        $data = $this->getJson('/api/v1/employees/'.$this->employee->id.'/payroll-inputs?month=2026-11')->assertOk()->json('data');

        $this->assertFalse($data['ready']);
        $this->assertFalse($data['financial_effect_applied']);
        $this->assertCount(30, $data['days']);
        $byDate = collect($data['days'])->keyBy('date');
        $this->assertSame(['not_approved'], $byDate['2026-11-03']['blockers']);
        $this->assertSame(['not_approved', 'leave_pending'], $byDate['2026-11-10']['blockers']);
        $this->assertSame(300, $byDate['2026-11-02']['worked_minutes']);
        $this->assertSame(1_000_000 + intdiv(2_000_000, 7), $data['summary']['leave'][0]['covered_units']);
        $this->assertSame(100, $data['summary']['leave'][0]['pay_percent']);
        $this->assertSame(0, $data['summary']['unpaid_leave_units']);
    }

    public function test_payroll_inputs_are_ready_once_every_pay_relevant_day_is_approved(): void
    {
        // Service starts on Thursday 29 October: Thursday, the Friday rest day and Saturday remain in the month.
        $this->employee->update(['hire_date' => '2026-10-29']);
        $this->employee->servicePeriods()->update(['start_date' => '2026-10-29']);
        $this->punches('2026-10-29', ['09:00', '16:00']);
        $this->punches('2026-10-31', ['09:00', '16:00'], '2026-10-31T16:00:00');
        Sanctum::actingAs($this->hr);
        $url = '/api/v1/employees/'.$this->employee->id.'/payroll-inputs?month=2026-10';
        $this->assertFalse($this->getJson($url)->assertOk()->json('data.ready'));

        $reviews = app(AttendanceReviewService::class);
        foreach (['2026-10-29', '2026-10-31'] as $date) {
            $day = $this->calculate($date);
            $reviews->approve($day, $day->version, $this->hr);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.blocked_days', 0)->assertJsonPath('data.summary.worked_minutes', 840);
    }

    public function test_report_counts_leave_days_without_marking_them_unknown(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03']);
        $this->approvedLeave(['start_date' => '2026-11-02', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-02', ['09:00', '16:00'], '2026-11-03T16:00:00');
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance-report?from=2026-11-02&to=2026-11-03')->assertOk()
            ->assertJsonPath('data.summary.leave_days', 1)
            ->assertJsonPath('data.summary.leave_minutes', 540)
            ->assertJsonPath('data.summary.worked_minutes', 300)
            ->assertJsonPath('data.summary.unknown_worked_days', 0);
    }

    public function test_ending_service_does_not_flag_approved_history_as_changed(): void
    {
        $this->punches('2026-11-02', ['09:00', '16:00']);
        $this->punches('2026-11-03', ['09:00', '16:00'], '2026-11-03T16:00:00');
        $reviews = app(AttendanceReviewService::class);
        foreach (['2026-11-02', '2026-11-03'] as $date) {
            $day = $this->calculate($date);
            $reviews->approve($day, $day->version, $this->hr);
        }

        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-11-02', 'reason' => 'Resigned'], $this->hr);
        $this->employee->refresh();

        // Inside the service period nothing changed; the day after the end really did change.
        $this->assertFalse($this->calculate('2026-11-02')->pending_changes);
        $this->assertTrue($this->calculate('2026-11-03')->pending_changes);
    }
}
