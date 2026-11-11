<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

class LeaveAttendanceCompletionTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase;

    private function person(string $no, string $role, ?int $departmentId = null): array
    {
        $user = $this->actor($role);
        $employee = Employee::create(['employee_no' => $no, 'user_id' => $user->id, 'first_name' => $no, 'last_name' => 'Test',
            'hire_date' => '2025-01-01', 'national_id' => $no, 'national_id_hash' => hash('sha256', $no), 'department_id' => $departmentId]);
        $employee->servicePeriods()->create(['start_date' => '2025-01-01']);

        return [$user, $employee];
    }

    private function leaveAs(User $user, array $input): array
    {
        Sanctum::actingAs($user);
        $input = ['leave_type_id' => $this->type->id, 'unit' => 'day', 'reason' => 'Family matter', ...$input];
        $input['end_date'] ??= $input['start_date'];
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');

        return $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'],
            'submission_key' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    public function test_bulk_approval_signs_off_clean_days_and_returns_the_rest(): void
    {
        $this->punches('2026-11-02', ['09:00', '16:00']);
        $this->punches('2026-11-03', ['09:00', '16:00'], '2026-11-03T16:00:00');
        $this->approvedLeave(['start_date' => '2026-11-04']);
        $this->punches('2026-11-04', ['09:00'], '2026-11-04T16:00:00'); // punch on a leave day: needs a person

        Sanctum::actingAs($this->employeeUser);
        $this->postJson('/api/v1/attendance/days/approve-bulk', ['from' => '2026-11-02', 'to' => '2026-11-05'])->assertForbidden();
        Sanctum::actingAs($this->hr);
        $result = $this->postJson('/api/v1/attendance/days/approve-bulk', ['from' => '2026-11-02', 'to' => '2026-11-05',
            'employee_ids' => [$this->employee->id]])->assertOk()->json('data');

        $this->assertSame(2, $result['approved']);
        $skipped = collect($result['skipped'])->keyBy('date');
        $this->assertSame(['2026-11-04', '2026-11-05'], $skipped->keys()->all());
        $this->assertStringContainsString('punch_on_leave_day', $skipped['2026-11-04']['reason']);
        $this->assertSame('لم ينته وقت الدوام بعد.', $skipped['2026-11-05']['reason']);
        $this->assertNotNull(AttendanceDay::where('work_date', '2026-11-03')->value('approved_at'));

        $again = $this->postJson('/api/v1/attendance/days/approve-bulk', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertOk();
        $again->assertJsonPath('data.approved', 0)->assertJsonPath('data.already_approved', 2);
    }

    public function test_company_manager_leave_is_approved_on_submission_and_cancelled_directly(): void
    {
        [$manager, $employee] = $this->person('CM-1', 'company_manager');
        $row = $this->leaveAs($manager, ['start_date' => '2026-11-05']);
        $this->assertSame('approved', $row['status']);
        $this->assertSame($manager->id, $row['reviewed_by']);
        $this->assertSame('on_leave', app(AttendanceCalculator::class)
            ->calculate($employee, '2026-11-05')->status);
        // Nobody is asked to review it.
        $this->assertSame(0, $this->hr->notifications()->count());

        $cancelled = $this->postJson('/api/v1/leaves/'.$row['id'].'/cancel', ['version' => $row['version'], 'reason' => 'Plans changed'])
            ->assertOk()->json('data');
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertNull($cancelled['change_request']);
        $this->assertSame('pending', AttendanceDay::where('employee_id', $employee->id)->where('work_date', '2026-11-05')->value('status'));
    }

    public function test_department_manager_sees_only_their_department_and_no_leave_reasons(): void
    {
        $engineering = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $site = Department::create(['name' => 'Site team', 'code' => 'ENG-S', 'parent_id' => $engineering->id]);
        $finance = Department::create(['name' => 'Finance', 'code' => 'FIN']);
        [$managerUser, $manager] = $this->person('DM-1', 'department_manager', $engineering->id);
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/departments/'.$engineering->id, ['manager_employee_id' => $manager->id])->assertOk();
        $this->employee->update(['department_id' => $site->id]); // sub-department: in scope
        [$outsiderUser, $outsider] = $this->person('FN-1', 'employee', $finance->id);
        $inside = $this->approvedLeave(['start_date' => '2026-11-03']);
        $outside = $this->leaveAs($outsiderUser, ['start_date' => '2026-11-03']);

        Sanctum::actingAs($managerUser);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance?date=2026-11-03')->assertOk();
        $this->getJson('/api/v1/employees/'.$outsider->id.'/attendance?date=2026-11-03')->assertForbidden();
        $this->getJson('/api/v1/attendance/days?date=2026-11-03')->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.employee.id', $this->employee->id);
        $list = $this->getJson('/api/v1/leaves?scope=all')->assertOk()->json('data');
        $this->assertSame([$inside['id']], array_column($list, 'id'));
        $this->assertArrayNotHasKey('reason', $list[0]['input']);
        $this->getJson('/api/v1/leaves/'.$outside['id'])->assertForbidden();
        $this->getJson('/api/v1/leaves/calendar?from=2026-11-01&to=2026-11-30&include_pending=1')->assertOk()
            ->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.employee_id', $this->employee->id);
        // Read-only: no approvals, no payroll figures.
        $this->postJson('/api/v1/leaves/'.$outside['id'].'/approve', ['version' => $outside['version'], 'reason' => 'ok'])->assertForbidden();
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/payroll-inputs?month=2026-11')->assertForbidden();
        $this->postJson('/api/v1/attendance/days/approve-bulk', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertForbidden();

        // HR keeps organisation-wide access including the reason.
        Sanctum::actingAs($this->hr);
        $all = $this->getJson('/api/v1/leaves?scope=all')->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertSame('Family matter', collect($all)->firstWhere('id', $outside['id'])['input']['reason']);
    }

    public function test_leave_list_filters_and_calendar_with_holidays(): void
    {
        $approved = $this->approvedLeave(['start_date' => '2026-11-03']);
        $pending = $this->leave(['start_date' => '2026-11-15']);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-12', 'name' => 'Office day'])->assertCreated();

        $ids = fn (string $query) => array_column($this->getJson('/api/v1/leaves?scope=all&'.$query)->assertOk()->json('data'), 'id');
        $this->assertSame([$pending['id']], $ids('status=pending'));
        $this->assertSame([$approved['id']], $ids('from=2026-11-01&to=2026-11-10'));
        $this->assertSame([$pending['id'], $approved['id']], $ids('employee_id='.$this->employee->id));
        $this->assertSame([], $ids('employee_id='.$this->employee->id.'&leave_type_id=999999'));

        $calendar = $this->getJson('/api/v1/leaves/calendar?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['2026-11-03'], array_column($calendar['days'], 'date'));
        $this->assertTrue($calendar['days'][0]['full_day']);
        $this->assertSame('Office day', $calendar['holidays'][0]['name']);
        $withPending = $this->getJson('/api/v1/leaves/calendar?from=2026-11-01&to=2026-11-30&include_pending=1')->json('data.days');
        $this->assertSame(['2026-11-03', '2026-11-15'], array_column($withPending, 'date'));
        $this->getJson('/api/v1/leaves/calendar?from=2026-01-01&to=2026-06-30')->assertUnprocessable();
        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/leaves/calendar?from=2026-11-01&to=2026-11-30')->assertForbidden();
    }

    public function test_leave_and_correction_events_notify_the_right_people(): void
    {
        $row = $this->leave(['start_date' => '2026-11-03']);
        $this->assertSame('leave.submitted', $this->hr->notifications()->first()?->data['kind']);
        $this->assertSame($row['id'], $this->hr->notifications()->first()->data['leave_request_id']);

        $this->leaveAction($row, 'approve', $this->hr);
        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'leave.approve');
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');

        // A punch on the approved leave day alerts attendance approvers once, not on every recalculation.
        $this->punches('2026-11-03', ['09:00'], '2026-11-03T16:00:00');
        $this->calculate('2026-11-03');
        $this->punches('2026-11-03', ['15:00']);
        $this->calculate('2026-11-03');
        $this->assertSame(1, $this->hr->notifications()->get()->where('data.kind', 'attendance.punch_on_leave_day')->count());

        // Correction requested by the employee reaches approvers; the decision reaches the employee.
        $this->punches('2026-11-02', ['09:00'], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-02');
        Sanctum::actingAs($this->employeeUser);
        $correction = $this->postJson('/api/v1/attendance/days/'.$day->id.'/corrections', ['version' => $day->version, 'type' => 'times',
            'first_in' => '2026-11-02T09:00:00+03:00', 'last_out' => '2026-11-02T16:00:00+03:00', 'reason' => 'Forgot to punch out'])
            ->assertCreated()->json('data');
        $this->assertSame(1, $this->hr->notifications()->get()->where('data.kind', 'attendance.correction_requested')->count());
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/corrections/'.$correction['id'].'/review', ['decision' => 'approve', 'reason' => 'Verified'])->assertOk();
        $this->assertSame(1, $this->employeeUser->notifications()->get()->where('data.kind', 'attendance.correction_approved')->count());
    }

    public function test_calendar_and_export_live_under_leaves_not_leave_settings(): void
    {
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/leave-settings/calendar?from=2026-11-01&to=2026-11-30')->assertNotFound();
        $this->get('/api/v1/leave-settings/export')->assertNotFound();
        $this->getJson('/api/v1/leaves/calendar?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->get('/api/v1/leaves/export')->assertOk();
    }

    public function test_exports_are_scoped_excel_friendly_and_neutralise_formulas(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03', 'reason' => '=HYPERLINK("http://x")']);
        $this->punches('2026-11-02', ['09:00', '16:00'], '2026-11-03T16:00:00');
        Sanctum::actingAs($this->hr);

        $csv = $this->get('/api/v1/leaves/export?scope=all')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame('request_id', $rows[0][0]);
        $this->assertCount(2, $rows);
        $this->assertSame('\'=HYPERLINK("http://x")', end($rows[1]));

        $attendance = $this->get('/api/v1/attendance/days/export?from=2026-11-02&to=2026-11-03')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", substr($attendance, 3))));
        $this->assertCount(3, $lines); // header + two days for the single employee
        $this->assertStringContainsString('present', $lines[1]);
        $this->assertStringContainsString('on_leave', $lines[2]);

        Sanctum::actingAs($this->employeeUser);
        $this->get('/api/v1/attendance/days/export?from=2026-11-02&to=2026-11-03')->assertForbidden();
        $this->get('/api/v1/leaves/export?scope=all')->assertForbidden();
        $mine = $this->get('/api/v1/leaves/export')->assertOk()->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", substr($mine, 3))));
    }

    public function test_employee_status_no_longer_accepts_on_leave(): void
    {
        $this->expectException(QueryException::class);
        $this->employee->forceFill(['status' => 'on_leave'])->save();
    }
}
