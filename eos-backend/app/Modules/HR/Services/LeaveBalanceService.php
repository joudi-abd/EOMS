<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Models\LeaveBalanceEntry;
use App\Modules\HR\Models\LeaveCycle;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;

class LeaveBalanceService
{
    public function __construct(private EmploymentPeriodService $employment, private AuditLogger $audit) {}

    public function policy(int $type, string $date): ?LeavePolicy
    {
        return LeavePolicy::where('leave_type_id', $type)->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first();
    }

    public function cycle(array $policy, Employee $employee, string $date): array
    {
        $monthDay = ($policy['year_basis'] ?? 'fixed') === 'anniversary'
            ? $employee->hire_date->format('m-d') : ($policy['year_start'] ?? '01-01');
        [$month, $day] = array_map('intval', explode('-', $monthDay));
        $boundary = function (int $year) use ($month, $day) {
            $start = CarbonImmutable::createMidnightDate($year, $month, 1);
            return $start->day(min($day, $start->daysInMonth));
        };
        $year = (int) substr($date, 0, 4);
        if ($boundary($year)->toDateString() > $date) {
            $year--;
        }
        return [$boundary($year)->toDateString(), $boundary($year + 1)->subDay()->toDateString()];
    }

    public function available(int $period, int $type, string $cycle): int
    {
        return (int) LeaveBalanceEntry::where('service_period_id', $period)->where('leave_type_id', $type)
            ->whereDate('cycle_start', $cycle)->sum('day_units');
    }

    /** Caller holds the employee row lock; manual writes and all workflows use that same lock. */
    public function sync(Employee $employee, EmployeeServicePeriod $period): void
    {
        $today = $this->employment->today();
        if ($employee->status === 'terminated' || $period->start_date->toDateString() > $today
            || ($period->end_date && $period->end_date->toDateString() < $today)) {
            return;
        }
        foreach (LeaveType::where('active', true)->orderBy('id')->get() as $type) {
            $policy = $this->policy($type->id, $today);
            $cycle = LeaveCycle::where('service_period_id', $period->id)->where('leave_type_id', $type->id)
                ->where('cycle_start', '<=', $today)->where('cycle_end', '>=', $today)->first();
            if (! $cycle && (! $policy || $policy->annual_days === null)) {
                continue;
            }
            if (! $cycle) {
                [$start, $end] = $this->cycle($policy->toArray(), $employee, $today);
                $opening = LeaveBalanceEntry::where('service_period_id', $period->id)->where('leave_type_id', $type->id)
                    ->whereDate('cycle_start', $start)->where('kind', 'opening')->exists();
                // HR must decide the opening on rehire, rather than automatically recreating a full entitlement.
                $rehire = $employee->servicePeriods()->where('id', '!=', $period->id)->exists()
                    && $period->start_date->toDateString() >= $start;
                if ($rehire && ! $opening) {
                    continue;
                }
                $cycle = LeaveCycle::create([
                    'service_period_id' => $period->id, 'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id,
                    'cycle_start' => $start, 'cycle_end' => $end,
                    'grant_start' => max($start, $period->start_date->toDateString(), $policy->effective_from->toDateString()),
                    'annual_units' => (int) round((float) $policy->annual_days * 1_000_000),
                    'manual' => $opening, 'policy' => $policy->toArray(),
                ]);
            }
            // Imported opening balances replace automatic grants for that cycle, avoiding double credit.
            if ($cycle->manual) {
                continue;
            }
            $snapshot = $cycle->policy;
            $firstYear = $period->start_date->toDateString() >= $cycle->cycle_start;
            $target = $cycle->annual_units;
            if ($snapshot['grant_method'] === 'monthly' && ! ($firstYear && $snapshot['first_year_method'] === 'upfront')) {
                // First installment on grant_start, then monthly anniversaries, clamped for short months.
                $anchor = CarbonImmutable::parse($cycle->grant_start);
                $total = 0;
                $due = 0;
                for ($i = 0; $i < 12; $i++) {
                    $installment = $anchor->addMonthsNoOverflow($i)->toDateString();
                    if ($installment > $cycle->cycle_end) {
                        break;
                    }
                    $total++;
                    if ($installment <= $today) {
                        $due++;
                    }
                }
                $divisor = $firstYear && $snapshot['first_year_method'] === 'remaining' ? max(1, $total) : 12;
                $target = intdiv($cycle->annual_units * $due, $divisor);
            }
            $delta = $target - $cycle->granted_units;
            if ($delta > 0) {
                $entry = LeaveBalanceEntry::create([
                    'service_period_id' => $period->id, 'leave_type_id' => $type->id, 'leave_policy_id' => $cycle->leave_policy_id,
                    'cycle_start' => $cycle->cycle_start, 'kind' => 'grant', 'day_units' => $delta,
                    'reason' => 'منح تلقائي حسب سياسة سنة الرصيد', 'recorded_by_user_id' => 0,
                ]);
                $cycle->update(['granted_units' => $target]);
                $this->audit->log('leave.balance_granted', $entry, null, $entry->getAttributes());
            }
        }
    }
}
