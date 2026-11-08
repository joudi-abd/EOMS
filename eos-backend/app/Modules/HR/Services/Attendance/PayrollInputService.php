<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly time and leave facts a future payroll run will consume. It deliberately computes no money:
 * deduction and pay rules belong to payroll. A month is "ready" only when every day that can affect pay
 * is approved and nothing is still waiting for review.
 */
class PayrollInputService
{
    public function __construct(private AttendanceCalculator $calculator, private AttendanceLeaveContext $leave) {}

    public function month(Employee $employee, string $month): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $to = $from->endOfMonth();
        $days = collect();
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $days->push($this->calculator->calculate($employee, $date->toDateString()));
        }
        $leaveDays = LeaveRequestDay::query()->whereIn('id', $days->pluck('leave_request_day_id')->filter())->get()->keyBy('id');
        $requests = LeaveRequest::query()->whereIn('id', $leaveDays->pluck('leave_request_id'))->get()->keyBy('id');
        $pendingCorrections = AttendanceCorrection::query()->whereIn('day_id', $days->pluck('id'))
            ->where('status', 'pending')->pluck('day_id')->flip();

        $policies = AttendancePolicy::query()->whereIn('id', $days->pluck('policy_id')->unique())->get()->keyBy('id');
        $rows = $days->map(function (AttendanceDay $day) use ($leaveDays, $requests, $pendingCorrections, $policies) {
            $date = $day->work_date->format('Y-m-d');
            $policy = $policies->get($day->policy_id);
            $start = AttendanceClock::at($date, $policy->start_time, $policy->timezone);
            $end = AttendanceClock::at($date, $policy->end_time, $policy->timezone);
            $leaveDay = $leaveDays->get($day->leave_request_day_id);
            $request = $leaveDay ? $requests->get($leaveDay->leave_request_id) : null;
            $blockers = [];
            if ($this->needsApproval($day) && $day->approved_at === null) {
                $blockers[] = 'not_approved';
            }
            if ($day->pending_changes) {
                $blockers[] = 'pending_changes';
            }
            if ($pendingCorrections->has($day->id)) {
                $blockers[] = 'correction_pending';
            }
            if ($day->status !== 'outside_service' && $this->leave->pendingRequestId($day->employee_id, $date) !== null) {
                $blockers[] = 'leave_pending';
            }
            if ($request?->change_request !== null && $request?->status === 'approved') {
                $blockers[] = 'leave_change_pending';
            }

            return [
                'date' => $date, 'status' => $day->status, 'approved' => $day->approved_at !== null,
                'pending_changes' => $day->pending_changes, 'flags' => $day->flags,
                'required_minutes' => $day->required_minutes, 'worked_minutes' => $day->worked_minutes,
                'late_minutes' => $day->late_minutes, 'early_minutes' => $day->early_minutes,
                'extra_minutes' => $day->extra_minutes, 'leave_minutes' => $day->leave_minutes,
                // Schedule facts payroll needs to price the day; independent of whether the employee worked.
                'scheduled_working_day' => in_array($start->dayOfWeek, $policy->working_days, true),
                'schedule_minutes' => intdiv($end->timestamp - $start->timestamp, 60),
                'early_arrival_minutes' => $day->first_in && $day->first_in->lessThan($start)
                    ? intdiv($start->timestamp - $day->first_in->timestamp, 60) : 0,
                'overtime_multiplier' => (float) $policy->overtime_multiplier,
                'leave' => $leaveDay ? [
                    'request_id' => $leaveDay->leave_request_id, 'leave_type_id' => $request?->leave_type_id,
                    'full_day' => $day->status === 'on_leave', 'pay_percent' => $leaveDay->pay_percent,
                    'day_units' => $leaveDay->day_units, 'covered_units' => $leaveDay->covered_units,
                    'unpaid_units' => $leaveDay->unpaid_units,
                ] : null,
                'blockers' => $blockers,
            ];
        });

        return [
            'employee_id' => $employee->id, 'month' => $month,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'ready' => $rows->every(fn ($row) => $row['blockers'] === []),
            'blocked_days' => $rows->filter(fn ($row) => $row['blockers'] !== [])->count(),
            'summary' => $this->summary($rows),
            'days' => $rows->values()->all(),
            'units_per_day' => 1_000_000, 'financial_effect_applied' => false,
        ];
    }

    /** Plain rest days and holidays nobody worked on cannot change pay; every other in-service day must be approved. */
    private function needsApproval(AttendanceDay $day): bool
    {
        if ($day->status === 'outside_service') {
            return false;
        }
        if (in_array($day->status, ['day_off', 'holiday'], true)) {
            return array_intersect(['work_on_day_off', 'work_on_holiday'], $day->flags) !== [];
        }

        return true;
    }

    private function summary(Collection $rows): array
    {
        $leave = $rows->pluck('leave')->filter();
        $types = LeaveType::query()->whereIn('id', $leave->pluck('leave_type_id')->unique())->pluck('name', 'id');

        return [
            'by_status' => $rows->countBy('status'),
            'required_minutes' => $rows->sum('required_minutes'), 'worked_minutes' => $rows->sum('worked_minutes'),
            'late_minutes' => $rows->sum('late_minutes'), 'early_minutes' => $rows->sum('early_minutes'),
            'extra_minutes' => $rows->sum('extra_minutes'), 'leave_minutes' => $rows->sum('leave_minutes'),
            'absent_days' => $rows->where('status', 'absent')->count(),
            // Grouped by type and pay rate, because one type's policy version can change within a month.
            'leave' => $leave->groupBy(fn ($row) => $row['leave_type_id'].':'.$row['pay_percent'])->map(fn ($group) => [
                'leave_type_id' => $group->first()['leave_type_id'], 'name' => $types->get($group->first()['leave_type_id']),
                'pay_percent' => $group->first()['pay_percent'], 'day_units' => $group->sum('day_units'),
                'covered_units' => $group->sum('covered_units'), 'unpaid_units' => $group->sum('unpaid_units'),
            ])->values()->all(),
            'unpaid_leave_units' => $leave->sum('unpaid_units'),
        ];
    }
}
