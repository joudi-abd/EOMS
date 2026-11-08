<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Services\EmploymentPeriodService;
use App\Modules\HR\Services\HrNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceCalculator
{
    public function calculate(Employee $employee, string $date): AttendanceDay
    {
        return DB::transaction(function () use ($employee, $date) {
            // One lock order for calculation, corrections and approvals: employee -> day.
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            [$serviceStart, $serviceEnd] = app(EmploymentPeriodService::class)->bounds($employee, $date);
            $inService = $date >= $serviceStart && ($serviceEnd === null || $date <= $serviceEnd);
            $policy = AttendancePolicy::query()->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')->firstOrFail();
            $day = AttendanceDay::query()->firstOrCreate([
                'employee_id' => $employee->id, 'work_date' => $date,
            ], ['policy_id' => $policy->id, 'flags' => []]);
            $day = AttendanceDay::query()->lockForUpdate()->findOrFail($day->id);
            $start = AttendanceClock::at($date, $policy->start_time, $policy->timezone);
            $end = AttendanceClock::at($date, $policy->end_time, $policy->timezone);
            $localDay = CarbonImmutable::parse($date, $policy->timezone)->startOfDay();
            $raw = AttendancePunch::query()->where('employee_id', $employee->id)
                ->where('occurred_at', '>=', $localDay->utc()->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $localDay->addDay()->utc()->format('Y-m-d H:i:s'))
                ->orderBy('occurred_at')->orderBy('id')->get();
            $deviceIds = AttendanceBinding::query()->where('employee_id', $employee->id)
                ->validOn($date)
                ->pluck('device_id')->unique();
            $devices = AttendanceDevice::query()->whereIn('id', $deviceIds)->get();
            // Disabled devices are NOT silently excluded from historical coverage.
            $covered = $devices->isNotEmpty() && $devices->every(fn ($d) => $d->complete_through !== null && $d->complete_through->greaterThanOrEqualTo($end));
            $closed = CarbonImmutable::now('UTC')->greaterThanOrEqualTo($end->addMinutes($policy->finalize_after_minutes));
            $correction = AttendanceCorrection::query()->where('day_id', $day->id)
                ->where('status', 'approved')->orderByDesc('id')->first();
            $working = in_array($localDay->dayOfWeek, $policy->working_days, true);
            // Leave and holidays only matter on scheduled working days inside the service period.
            $context = app(AttendanceLeaveContext::class);
            $holiday = $working && $inService ? $context->holiday($date) : null;
            $leave = $working && $inService && ! $holiday ? $this->leaveWindow($context, $employee->id, $date, $policy, $start, $end) : null;
            $fullLeave = $leave !== null && $leave['full'];
            $phaseSensitive = $raw->isEmpty() && $correction === null && $working && $inService
                && ! $holiday && ! $fullLeave;
            $source = [
                // false: retired employee-level on_leave flag, kept so existing signatures stay valid.
                $policy->id, $serviceStart, $serviceEnd, false,
                $raw->map(fn ($p) => [$p->id, $p->occurred_at->timestamp, $p->direction])->all(),
                $deviceIds->sort()->values()->all(), $covered,
                $phaseSensitive ? $closed : null, $correction?->id,
            ];
            // Appended only when present, so days without leave or holidays keep their existing signature
            // and previously approved days do not all turn into pending changes after deployment.
            if ($holiday || $leave) {
                $source[] = ['holiday' => $holiday?->id,
                    'leave' => $leave === null ? null : [$leave['day']->id, $leave['from']->timestamp, $leave['to']->timestamp]];
            }
            if ($leave !== null && ! $fullLeave) {
                // Recalculate old partial-leave drafts; approved snapshots require an explicit review.
                $source[] = 'partial-leave-exempt-absence-v2';
            }
            $signature = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
            if ($day->approved_at !== null) {
                $day->update(['pending_changes' => $signature !== $day->source_signature]);

                return $day->refresh();
            }
            if ($day->source_signature === $signature) {
                return $day;
            }

            $flags = [];
            $punches = [];
            foreach ($raw as $punch) {
                $last = $punches === [] ? null : $punches[array_key_last($punches)];
                if ($last && $last->direction === $punch->direction
                    && $last->occurred_at->diffInSeconds($punch->occurred_at) <= $policy->duplicate_seconds) {
                    $flags[] = 'duplicate_punch_ignored';

                    continue;
                }
                $punches[] = $punch;
            }
            $first = null;
            $last = null;
            if ($policy->pairing_mode === 'first_last') {
                if (count($punches) > 0) {
                    $first = $punches[0]->occurred_at;
                    $flags[] = 'inferred_first_last';
                }
                if (count($punches) >= 2) {
                    $last = $punches[array_key_last($punches)]->occurred_at;
                }
            } else {
                $ins = array_values(array_filter($punches, fn ($p) => $p->direction === 'in'));
                $outs = array_values(array_filter($punches, fn ($p) => $p->direction === 'out'));
                $first = $ins[0]->occurred_at ?? null;
                $last = $outs === [] ? null : $outs[array_key_last($outs)]->occurred_at;
                if (count($ins) + count($outs) !== count($punches)) {
                    $flags[] = 'unclassified_punches';
                }
                if ($first && $last && ($last->lessThanOrEqualTo($first)
                    || collect($outs)->contains(fn ($p) => $p->occurred_at->lessThan($first))
                    || collect($ins)->contains(fn ($p) => $p->occurred_at->greaterThan($last)))) {
                    $flags[] = 'direction_conflict';
                    $last = null;
                }
            }
            if (count($punches) > 2) {
                $flags[] = 'multiple_punches_no_break_deduction';
            }
            if ($correction) {
                $first = $correction->first_in;
                $last = $correction->last_out;
                $flags = ['manual_correction'];
            }
            $outside = ! $inService;
            // Partial leave at an edge moves the required window; leave in the middle keeps both edges.
            $requiredStart = $start;
            $requiredEnd = $end;
            $leaveSeconds = 0;
            if ($leave !== null) {
                $leaveSeconds = $leave['to']->timestamp - $leave['from']->timestamp;
                if (! $fullLeave && $leave['from']->lessThanOrEqualTo($start)) {
                    $requiredStart = $leave['to'];
                }
                if (! $fullLeave && $leave['to']->greaterThanOrEqualTo($end)) {
                    $requiredEnd = $leave['from'];
                }
            }
            $validPair = $first && $last && $last->greaterThan($first);
            $lateSeconds = $first ? $this->unexcusedSeconds($requiredStart->timestamp, $first->timestamp, $leave) : null;
            $lateActual = $lateSeconds === null ? null : (int) ceil($lateSeconds / 60);
            $late = $first ? app(LatenessCharge::class)->minutes(
                $lateSeconds,
                $policy->grace_minutes,
                $policy->grace_mode,
                $policy->late_block_minutes,
            ) : null;
            $worked = null;
            if ($validPair) {
                $overlap = $leave === null ? 0 : max(0, min($last->timestamp, $leave['to']->timestamp) - max($first->timestamp, $leave['from']->timestamp));
                $worked = (int) floor(($last->timestamp - $first->timestamp - $overlap) / 60);
            }
            $early = $last ? (int) ceil($this->unexcusedSeconds($last->timestamp, $requiredEnd->timestamp, $leave) / 60) : null;
            // Overtime is measured from the scheduled end, never from a leave-shortened end.
            $extra = $last ? max(0, (int) floor(($last->timestamp - $end->timestamp) / 60)) : null;
            $required = $working && ! $outside && ! $holiday
                ? intdiv($end->timestamp - $start->timestamp - $leaveSeconds, 60) : null;
            if ($leave !== null && ! $fullLeave) {
                $flags[] = 'partial_leave';
                if (collect($punches)->contains(fn ($p) => $p->occurred_at->greaterThan($leave['from']) && $p->occurred_at->lessThan($leave['to']))) {
                    $flags[] = 'punch_during_leave';
                }
            }
            if ($outside) {
                $status = 'outside_service';
                $worked = $lateActual = $late = $early = $extra = null;
                if ($raw->isNotEmpty()) {
                    $flags[] = 'punch_outside_service';
                }
            } elseif (! $working) {
                $status = 'day_off';
                $lateActual = $late = $early = null;
                if ($raw->isNotEmpty()) {
                    $flags[] = 'work_on_day_off';
                }
            } elseif ($holiday) {
                $status = 'holiday';
                $lateActual = $late = $early = null;
                if ($raw->isNotEmpty()) {
                    $flags[] = 'work_on_holiday';
                }
            } elseif ($fullLeave) {
                // The approved leave wins; punches stay stored and surface as a conflict for HR.
                $status = 'on_leave';
                $worked = 0;
                $lateActual = $late = $early = $extra = null;
                if ($correction) {
                    $flags = ['correction_superseded_by_leave'];
                }
                if ($raw->isNotEmpty()) {
                    $flags[] = 'punch_on_leave_day';
                }
            } elseif ($correction?->type === 'absence') {
                $status = 'absent';
                $worked = 0;
            } elseif ($validPair && ! in_array('unclassified_punches', $flags, true)) {
                $status = 'present';
            } elseif ($raw->isNotEmpty() || $first || $last) {
                $status = 'incomplete';
                $flags[] = 'missing_or_ambiguous_punch';
            } elseif (! $closed) {
                $status = 'pending';
            } else {
                $status = $covered ? 'absent' : 'absent_unconfirmed';
                $worked = $covered ? 0 : null;
            }
            if (! $covered && ! $outside && $working && ! $holiday && ! $fullLeave) {
                $flags[] = 'sync_unconfirmed';
            }
            $hadConflict = in_array('punch_on_leave_day', $day->flags ?? [], true);
            $day->fill([
                'policy_id' => $policy->id, 'status' => $status, 'first_in' => $first,
                'last_out' => $last, 'worked_minutes' => $worked,
                'leave_request_day_id' => $leave['day']->id ?? null, 'required_minutes' => $required,
                'leave_minutes' => $leave === null ? null : intdiv($leaveSeconds, 60),
                'late_actual_minutes' => $lateActual, 'late_minutes' => $late,
                'early_minutes' => $early, 'extra_minutes' => $extra,
                'coverage_complete' => $covered, 'flags' => array_values(array_unique($flags)),
                'source_signature' => $signature, 'version' => $day->version + 1,
                'pending_changes' => false,
            ])->save();
            // Notify once, when the conflict first appears, not on every scheduled recalculation.
            if (! $hadConflict && in_array('punch_on_leave_day', $day->flags, true)) {
                app(HrNotifier::class)->attendanceConflict($day, 'punch_on_leave_day');
            }

            return $day->refresh();
        }, 3);
    }

    /** Subtract only the approved leave overlapping the missing attendance interval, before rounding. */
    private function unexcusedSeconds(int $from, int $to, ?array $leave): int
    {
        $duration = max(0, $to - $from);
        $excused = $leave === null ? 0 : max(0, min($to, $leave['to']->timestamp) - max($from, $leave['from']->timestamp));

        return max(0, $duration - $excused);
    }

    /**
     * The approved leave interval clipped to this day's schedule, or null when it covers no scheduled time.
     *
     * @return array{day: LeaveRequestDay, from: CarbonImmutable, to: CarbonImmutable, full: bool}|null
     */
    private function leaveWindow(AttendanceLeaveContext $context, int $employeeId, string $date, AttendancePolicy $policy, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $leaveDay = $context->approvedDay($employeeId, $date);
        if (! $leaveDay) {
            return null;
        }
        // Leave times are office-local wall clock times, like the attendance schedule.
        $from = AttendanceClock::at($date, substr($leaveDay->start_time, 0, 5), $policy->timezone)->max($start);
        $to = AttendanceClock::at($date, substr($leaveDay->end_time, 0, 5), $policy->timezone)->min($end);
        if (! $to->greaterThan($from)) {
            return null;
        }

        return ['day' => $leaveDay, 'from' => $from, 'to' => $to,
            'full' => $from->lessThanOrEqualTo($start) && $to->greaterThanOrEqualTo($end)];
    }
}
