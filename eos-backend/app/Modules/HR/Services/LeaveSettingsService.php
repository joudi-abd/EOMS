<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceEntry;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\Attendance\AttendanceLeaveSync;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveSettingsService
{
    public function __construct(private AuditLogger $audit, private EmploymentPeriodService $employment) {}

    public function settings(): array
    {
        return [
            'types' => LeaveType::query()->orderBy('id')->get(),
            'policies' => LeavePolicy::query()->orderByDesc('effective_from')->get(),
            'holidays' => OfficeHoliday::query()->orderBy('date')->get(),
            'today' => $this->employment->today(),
        ];
    }

    public function addType(string $name): LeaveType
    {
        return DB::transaction(function () use ($name) {
            $type = LeaveType::create(['name' => $name, 'active' => false]);
            $this->audit->log('leave.type_created', $type, null, $type->getAttributes());

            return $type;
        });
    }

    public function publish(LeaveType $type, array $data): LeavePolicy
    {
        return DB::transaction(function () use ($type, $data) {
            $type = LeaveType::query()->lockForUpdate()->findOrFail($type->getKey());
            $latest = LeavePolicy::query()->where('leave_type_id', $type->id)->max('effective_from');
            if ($data['effective_from'] < $this->employment->today() || ($latest && $data['effective_from'] <= substr($latest, 0, 10))) {
                throw ValidationException::withMessages(['effective_from' => 'اختر تاريخ سريان من اليوم فصاعداً وبعد آخر نسخة.']);
            }

            // Balance entries share the type's calendar across policy versions.
            $calendar = LeavePolicy::query()->where('leave_type_id', $type->id)
                ->whereNotNull('annual_days')->orderBy('effective_from')->first();
            if (($data['annual_days'] ?? null) !== null && $calendar
                && ($calendar->year_basis !== $data['year_basis']
                    || $calendar->year_start !== ($data['year_start'] ?? null))) {
                throw ValidationException::withMessages(['year_basis' => 'تقويم سنة الرصيد ثابت بعد نشر أول سياسة برصيد رقمي.']);
            }

            $policy = LeavePolicy::create(['leave_type_id' => $type->id, ...$data]);
            $this->audit->log('leave.policy_published', $policy, null, $policy->getAttributes());

            return $policy;
        }, 3);
    }

    public function activate(LeaveType $type, bool $active): LeaveType
    {
        return DB::transaction(function () use ($type, $active) {
            $type = LeaveType::query()->lockForUpdate()->findOrFail($type->getKey());
            if ($active && ! $this->currentPolicy($type, $this->employment->today())) {
                throw ValidationException::withMessages(['active' => 'يلزم وجود سياسة سارية قبل تفعيل النوع.']);
            }
            if ($type->active !== $active) {
                $old = $type->active;
                $type->update(['active' => $active]);
                $this->audit->log('leave.type_activation_changed', $type, ['active' => $old], ['active' => $active]);
            }

            return $type;
        }, 3);
    }

    public function addHoliday(array $data): OfficeHoliday
    {
        return DB::transaction(function () use ($data) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $holiday = OfficeHoliday::create($data);
            app(LeaveWorkflowService::class)->holidayAdded($holiday->date->toDateString());
            // Leave refunds above run first, so attendance sees the holiday and no stale leave day.
            app(AttendanceLeaveSync::class)->date($holiday->date->toDateString());
            $this->audit->log('leave.holiday_created', $holiday, null, $holiday->getAttributes());

            return $holiday;
        });
    }

    public function removeHoliday(OfficeHoliday $holiday): void
    {
        DB::transaction(function () use ($holiday) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $holiday = OfficeHoliday::query()->lockForUpdate()->findOrFail($holiday->getKey());
            foreach (\App\Modules\HR\Models\LeaveRequest::whereIn('status', ['pending', 'approved'])->cursor() as $request) {
                if ($request->input['start_date'] <= $holiday->date->toDateString() && $request->input['end_date'] >= $holiday->date->toDateString()) {
                    throw ValidationException::withMessages(['date' => 'توجد إجازة تتقاطع مع العطلة؛ راجع الإجازة قبل حذف العطلة.']);
                }
            }
            $this->audit->log('leave.holiday_removed', $holiday, $holiday->getAttributes());
            $date = $holiday->date->toDateString();
            $holiday->delete();
            app(AttendanceLeaveSync::class)->date($date);
        }, 3);
    }

    /** Rehired employees still waiting for HR to record an opening balance, with the blocked leave types. */
    public function pendingOpenings(): array
    {
        $balances = app(LeaveBalanceService::class);
        $today = $this->employment->today();
        $rows = [];
        $employees = Employee::query()->where('status', '!=', 'terminated')
            ->whereHas('servicePeriods', fn ($q) => $q->whereDate('start_date', '<=', $today)->where(fn ($p) => $p->whereNull('end_date')->orWhereDate('end_date', '>=', $today)))
            ->has('servicePeriods', '>', 1)->orderBy('id')->get();
        foreach ($employees as $employee) {
            $period = $employee->servicePeriods()->whereDate('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->first();
            $types = $balances->pendingOpenings($employee, $period);
            if ($types !== []) {
                $rows[] = ['employee_id' => $employee->id, 'employee_no' => $employee->employee_no, 'employee_name' => $employee->full_name,
                    'service_period_id' => $period->id, 'service_start' => $period->start_date->toDateString(), 'leave_types' => $types];
            }
        }

        return $rows;
    }

    public function balanceEntries(int $employeeId): LengthAwarePaginator
    {
        return LeaveBalanceEntry::query()
            ->whereIn('service_period_id', Employee::findOrFail($employeeId)->servicePeriods()->select('id'))
            ->orderByDesc('id')->paginate(25);
    }

    public function recordBalance(array $data, int $actor): LeaveBalanceEntry
    {
        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);
            $type = LeaveType::query()->lockForUpdate()->findOrFail($data['leave_type_id']);
            $today = $this->employment->today();
            $period = $employee->servicePeriods()->whereNull('end_date')->whereDate('start_date', '<=', $today)->first();
            $policy = $this->currentPolicy($type, $today);
            if ($employee->status === 'terminated' || ! $period || $policy?->annual_days === null) {
                throw ValidationException::withMessages(['days' => 'يلزم وجود فترة خدمة سارية وسياسة ذات رصيد رقمي.']);
            }

            $cycle = $this->cycleStart($policy, $employee, $today);
            $entries = LeaveBalanceEntry::query()
                ->where('service_period_id', $period->id)->where('leave_type_id', $type->id)
                ->whereDate('cycle_start', $cycle);
            $units = $this->dayUnits((string) $data['days']);
            if ($data['kind'] === 'opening' && ($units < 0 || $entries->exists())) {
                throw ValidationException::withMessages(['days' => 'الرصيد الافتتاحي غير سالب ويُسجّل مرة واحدة قبل التسويات.']);
            }
            if ($data['kind'] === 'adjustment' && ($units === 0 || ! $entries->exists() || $entries->sum('day_units') + $units < 0)) {
                throw ValidationException::withMessages(['days' => 'التسوية تحتاج رصيداً افتتاحياً أو منحة مسجلة ولا يجوز أن تجعل الرصيد المتاح سالباً.']);
            }

            $entry = LeaveBalanceEntry::create([
                'service_period_id' => $period->id, 'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id,
                'cycle_start' => $cycle, 'kind' => $data['kind'], 'day_units' => $units,
                'reason' => $data['reason'], 'recorded_by_user_id' => $actor,
            ]);
            $this->audit->log('leave.balance_recorded', $entry, null, $entry->getAttributes(), $actor);

            return $entry;
        }, 3);
    }

    private function currentPolicy(LeaveType $type, string $date): ?LeavePolicy
    {
        return LeavePolicy::query()->where('leave_type_id', $type->id)
            ->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    private function cycleStart(LeavePolicy $policy, Employee $employee, string $today): string
    {
        $monthDay = $policy->year_basis === 'anniversary' ? $employee->hire_date->format('m-d') : $policy->year_start;
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        $year = (int) substr($today, 0, 4);
        $start = CarbonImmutable::createMidnightDate($year, $month, 1);
        $start = $start->day(min($day, $start->daysInMonth));
        if ($start->toDateString() > $today) {
            $start = CarbonImmutable::createMidnightDate($year - 1, $month, 1);
            $start = $start->day(min($day, $start->daysInMonth));
        }

        return $start->toDateString();
    }

    private function dayUnits(string $days): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($days, '-'), 2), 2, '');
        $units = (int) $whole * 1_000_000 + (int) str_pad($fraction, 6, '0');

        return str_starts_with($days, '-') ? -$units : $units;
    }
}
