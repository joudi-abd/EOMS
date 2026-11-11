<?php

namespace App\Modules\Tasks\Services;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\OfficeHoliday;
use Carbon\CarbonImmutable;

/** The office calendar as HR defines it: the shift's working days, public holidays and approved leave. */
class WorkCalendar
{
    public function zone(): string
    {
        return AttendancePolicy::query()->orderBy('effective_from')->value('timezone') ?? config('app.timezone');
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->zone())->toDateString();
    }

    /** True from an hour before today's shift start, office time (when recurring cards appear). */
    public function workdayStarted(): bool
    {
        $now = CarbonImmutable::now($this->zone());
        $policy = AttendancePolicy::query()->where('effective_from', '<=', $now->toDateString())->orderByDesc('effective_from')->first();
        $start = $policy?->start_time ? substr((string) $policy->start_time, 0, 5) : '08:00';

        return $now->format('H:i') >= CarbonImmutable::parse($now->toDateString().' '.$start, $this->zone())->subHour()->format('H:i');
    }

    public function isWorkingDay(string $date): bool
    {
        $policy = AttendancePolicy::query()->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first()
            ?? AttendancePolicy::query()->orderBy('effective_from')->first();
        $workingDays = $policy?->working_days ?? [0, 1, 2, 3, 4, 6];

        return in_array(CarbonImmutable::parse($date)->dayOfWeek, $workingDays, true)
            && ! OfficeHoliday::query()->whereDate('date', $date)->exists();
    }

    /** The first working day strictly after $date. */
    public function nextWorkingDay(string $date): string
    {
        $day = CarbonImmutable::parse($date);
        for ($i = 0; $i < 60; $i++) {
            $day = $day->addDay();
            if ($this->isWorkingDay($day->toDateString())) {
                return $day->toDateString();
            }
        }

        return $day->toDateString();
    }

    /** $days working days after $date ($date itself when 0). */
    public function addWorkingDays(string $date, int $days): string
    {
        for ($i = 0; $i < $days; $i++) {
            $date = $this->nextWorkingDay($date);
        }

        return $date;
    }

    /**
     * Of these users, who has approved leave on $date (whole or part of the day). A warning only: assigning
     * them is still allowed.
     *
     * @return list<int>
     */
    public function onLeave(array $userIds, string $date): array
    {
        if ($userIds === []) {
            return [];
        }
        $employees = Employee::query()->whereIn('user_id', $userIds)->pluck('user_id', 'id');
        $onLeave = LeaveRequestDay::query()->whereIn('employee_id', $employees->keys())->whereDate('date', $date)
            ->where('active', true)
            ->whereIn('leave_request_id', LeaveRequest::query()->where('status', 'approved')->select('id'))
            ->pluck('employee_id')->unique();

        return $onLeave->map(fn ($id) => (int) $employees[$id])->values()->all();
    }
}
