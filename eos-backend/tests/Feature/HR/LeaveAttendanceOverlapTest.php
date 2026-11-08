<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

class LeaveAttendanceOverlapTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase;

    public static function attendanceIntervals(): array
    {
        // first, last, actual lateness, charged lateness, early departure, worked, punch inside leave
        return [
            'arrives after leave' => ['13:00', '16:00', 120, 120, 0, 180, false],
            'arrives inside leave' => ['12:00', '16:00', 120, 120, 0, 180, true],
            'arrives at leave start' => ['11:00', '16:00', 120, 120, 0, 180, false],
            'leaves at leave start' => ['09:00', '11:00', 0, 0, 180, 120, false],
            'leaves inside leave' => ['09:00', '12:00', 0, 0, 180, 120, true],
            'leaves after leave' => ['09:00', '14:00', 0, 0, 120, 180, false],
            'leaves before leave' => ['09:00', '10:00', 0, 0, 240, 60, false],
            'late and early' => ['10:00', '14:00', 60, 60, 120, 120, false],
            'grace preserved' => ['09:45', '16:00', 45, 0, 0, 255, false],
            'grace exceeded' => ['09:46', '16:00', 46, 60, 0, 254, false],
            'outside shift boundaries' => ['08:30', '16:30', 0, 0, 0, 360, false],
        ];
    }

    #[DataProvider('attendanceIntervals')]
    public function test_approved_leave_is_excluded_from_missing_attendance_intervals(
        string $first, string $last, int $actual, int $charged, int $early, int $worked, bool $conflict
    ): void {
        $this->approvedLeave(['start_date' => '2026-11-03', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-03', [$first, $last], '2026-11-03T17:00:00');
        $day = $this->calculate('2026-11-03');

        $this->assertSame($actual, $day->late_actual_minutes);
        $this->assertSame($charged, $day->late_minutes);
        $this->assertSame($early, $day->early_minutes);
        $this->assertSame($worked, $day->worked_minutes);
        $this->assertSame(300, $day->required_minutes);
        $this->assertSame(120, $day->leave_minutes);
        $this->assertSame($conflict, in_array('punch_during_leave', $day->flags, true));
    }

    public function test_pending_leave_does_not_exempt_lateness(): void
    {
        $this->leave(['start_date' => '2026-11-03', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-03', ['13:00', '16:00'], '2026-11-03T16:00:00');
        $this->assertSame(240, $this->calculate('2026-11-03')->late_minutes);
    }

    public function test_old_approved_partial_leave_snapshot_requires_reopening_before_recalculation(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-03', 'unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->punches('2026-11-03', ['13:00', '16:00'], '2026-11-03T16:00:00');
        $day = $this->calculate('2026-11-03');
        // Persist the pre-fix signature and amounts as an upgraded installation would contain them.
        $source = [$day->policy_id, '2025-01-01', null, false,
            AttendancePunch::where('employee_id', $this->employee->id)->orderBy('occurred_at')->orderBy('id')->get()
                ->map(fn ($p) => [$p->id, $p->occurred_at->timestamp, $p->direction])->all(),
            [$this->device->id], true, null, null,
            ['holiday' => null, 'leave' => [$day->leave_request_day_id, strtotime('2026-11-03T11:00:00+03:00'), strtotime('2026-11-03T13:00:00+03:00')]],
        ];
        $day->update(['source_signature' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
            'late_actual_minutes' => 240, 'late_minutes' => 240, 'approved_at' => now(), 'approved_by' => $this->hr->id]);
        $day = $this->calculate('2026-11-03');
        $this->assertTrue($day->pending_changes);
        $this->assertSame(240, $day->late_minutes);

        $day = app(AttendanceReviewService::class)->reopen($day, $day->version, 'Review corrected leave overlap', $this->hr);
        $this->assertFalse($day->pending_changes);
        $this->assertNull($day->approved_at);
        $this->assertSame(120, $day->late_minutes);
        $this->assertSame($day->version, $this->calculate('2026-11-03')->version);
    }

    public function test_full_leave_resource_hides_unsupported_correction_action_and_exposes_conflict(): void
    {
        $leave = $this->approvedLeave(['start_date' => '2026-11-03']);
        $this->punches('2026-11-03', ['09:00', '16:00'], '2026-11-03T16:00:00');
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/employees/'.$this->employee->id.'/attendance?date=2026-11-03')->assertOk()
            ->assertJsonPath('data.day.permissions.can_request_correction', false)
            ->assertJsonPath('data.day.leave.request_id', $leave['id'])
            ->assertJsonPath('data.day.leave_minutes', 420)
            ->assertJsonPath('data.day.required_minutes', 0)
            ->assertJsonPath('data.day.flags', fn ($flags) => in_array('punch_on_leave_day', $flags, true));
    }
}
