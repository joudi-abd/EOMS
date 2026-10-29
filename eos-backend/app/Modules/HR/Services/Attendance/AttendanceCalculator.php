<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceCalculator
{
    public function calculate(Employee $employee, string $date): AttendanceDay
    {
        return DB::transaction(function () use ($employee, $date) {
            // One lock order for calculation, corrections and approvals: employee -> day.
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
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
                ->where('valid_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
                ->pluck('device_id')->unique();
            $devices = AttendanceDevice::query()->whereIn('id', $deviceIds)->get();
            // Disabled devices are NOT silently excluded from historical coverage.
            $covered = $devices->isNotEmpty() && $devices->every(fn ($d) => $d->complete_through !== null && $d->complete_through->greaterThanOrEqualTo($end));
            $closed = CarbonImmutable::now('UTC')->greaterThanOrEqualTo($end->addMinutes($policy->finalize_after_minutes));
            $correction = AttendanceCorrection::query()->where('day_id', $day->id)
                ->where('status', 'approved')->orderByDesc('id')->first();
            $phaseSensitive = $raw->isEmpty() && $correction === null
                && in_array($localDay->dayOfWeek, $policy->working_days, true)
                && $date >= $employee->hire_date->format('Y-m-d')
                && ($employee->termination_date === null || $date <= $employee->termination_date->format('Y-m-d'));
            $signature = hash('sha256', json_encode([
                $policy->id, $employee->hire_date?->format('Y-m-d'),
                $employee->termination_date?->format('Y-m-d'),
                $employee->status === 'on_leave' && $date === CarbonImmutable::now($policy->timezone)->toDateString(),
                $raw->map(fn ($p) => [$p->id, $p->occurred_at->timestamp, $p->direction])->all(),
                $deviceIds->sort()->values()->all(), $covered,
                $phaseSensitive ? $closed : null, $correction?->id,
            ], JSON_THROW_ON_ERROR));
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
            $outside = $date < $employee->hire_date->format('Y-m-d')
                || ($employee->termination_date && $date > $employee->termination_date->format('Y-m-d'));
            $working = in_array($localDay->dayOfWeek, $policy->working_days, true);
            $validPair = $first && $last && $last->greaterThan($first);
            $lateActual = $first ? max(0, (int) ceil(($first->timestamp - $start->timestamp) / 60)) : null;
            $late = $first ? app(LatenessCharge::class)->minutes(
                $first->timestamp - $start->timestamp,
                $policy->grace_minutes,
                $policy->grace_mode,
                $policy->late_block_minutes,
            ) : null;
            $worked = $validPair ? (int) floor(($last->timestamp - $first->timestamp) / 60) : null;
            $early = $last ? max(0, (int) ceil(($end->timestamp - $last->timestamp) / 60)) : null;
            $extra = $last ? max(0, (int) floor(($last->timestamp - $end->timestamp) / 60)) : null;
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
            if (! $covered && ! $outside && $working) {
                $flags[] = 'sync_unconfirmed';
            }
            if ($employee->status === 'on_leave' && $date === CarbonImmutable::now($policy->timezone)->toDateString()) {
                $flags[] = 'leave_requires_review';
                if ($status === 'absent') {
                    $status = 'absent_unconfirmed';
                    $worked = null;
                }
            }
            $day->fill([
                'policy_id' => $policy->id, 'status' => $status, 'first_in' => $first,
                'last_out' => $last, 'worked_minutes' => $worked,
                'late_actual_minutes' => $lateActual, 'late_minutes' => $late,
                'early_minutes' => $early, 'extra_minutes' => $extra,
                'coverage_complete' => $covered, 'flags' => array_values(array_unique($flags)),
                'source_signature' => $signature, 'version' => $day->version + 1,
                'pending_changes' => false,
            ])->save();

            return $day->refresh();
        }, 3);
    }
}
