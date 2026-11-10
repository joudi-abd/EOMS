<?php

namespace App\Modules\Payroll\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\Attendance\PayrollInputService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Prices one employee's month from approved attendance and leave (HR payroll inputs) and the contract in
 * force on each day. Pure calculation: it writes nothing. All amounts are in the contract currency.
 *
 * Agreed rules (payroll analysis):
 * - monthly: day rate = salary ÷ scheduled working days of the month (holidays included); hour rate = day ÷ schedule hours.
 *   Paid for every in-service scheduled day; unpaid leave and the unpaid share of partially paid leave are deducted
 *   automatically; lateness, early exit and absence are proposed deductions for the company manager.
 * - daily: paid for present days and the paid share of leave; holidays and rest days unpaid; lateness/early proposed.
 * - hourly: paid for minutes worked inside the schedule, paid leave by its minutes, holidays by schedule minutes.
 * - overtime for everyone: post-shift minutes capped by the valid in/out span (and never above worked minutes),
 *   or every minute worked on a rest day or holiday, × hour rate × the attendance policy multiplier.
 *   Arrival before the start is never paid.
 */
class PayrollCalculator
{
    /** Kinds whose amount the company manager decides; everything else is applied as calculated. */
    public const PROPOSED_KINDS = ['late', 'early_exit', 'absence'];

    public function __construct(private PayrollInputService $inputs) {}

    public function forEmployee(Employee $employee, string $month): array
    {
        $input = $this->inputs->month($employee, $month);
        $rows = collect($input['days']);
        $workingDays = $rows->where('scheduled_working_day', true)->count();
        $contracts = $this->contracts($employee, $input['from'], $input['to']);
        $issues = [];
        $items = [];
        $currencies = [];
        $types = [];
        $last = null;

        foreach ($rows as $row) {
            if ($row['status'] === 'outside_service') {
                continue;
            }
            $contract = $this->contractOn($contracts, $row['date']);
            if (! $contract || $contract->base_salary === null || ! $contract->currency_code) {
                $issues['no_contract'][] = $row['date'];

                continue;
            }
            $last = $contract;
            $currencies[$contract->currency_code] = true;
            $types[$contract->pay_type] = true;
            $this->priceDay($items, $row, $contract, $workingDays);
        }
        if (count($currencies) > 1) {
            $issues['mixed_currency'] = array_keys($currencies);
        }

        $items = collect($items)->map(function (array $item, string $kind) {
            $item['amount'] = round($item['amount'], 2);
            $item['quantity'] = round($item['quantity'], 4);
            $item['details'] = array_map(fn ($d) => [...$d, 'amount' => round($d['amount'], 2)], $item['details']);

            return ['kind' => $kind, ...$item];
        })->filter(fn ($item) => $item['amount'] > 0)->values()->all();

        return [
            'employee_id' => $employee->id, 'month' => $month,
            'contract_id' => $last?->id, 'pay_type' => $last?->pay_type, 'pay_types' => array_keys($types),
            'currency_code' => $last?->currency_code, 'rate' => $last?->base_salary === null ? null : (float) $last->base_salary,
            'working_days' => $workingDays, 'attendance_ready' => (bool) $input['ready'],
            'blocked_days' => (int) $input['blocked_days'], 'issues' => $this->issueList($issues),
            'items' => $items,
        ];
    }

    private function priceDay(array &$items, array $row, EmploymentContract $contract, int $workingDays): void
    {
        $amount = (float) $contract->base_salary;
        $hours = max(1, $row['schedule_minutes']) / 60;
        $type = $contract->pay_type;
        $dayRate = match ($type) {
            'monthly' => $workingDays > 0 ? $amount / $workingDays : 0.0,
            'daily' => $amount,
            default => $amount * $hours,
        };
        $hourRate = $type === 'hourly' ? $amount : $dayRate / $hours;
        $status = $row['status'];
        $leave = $row['leave'];
        $date = $row['date'];

        // Base pay for the day.
        if ($type === 'monthly' && $row['scheduled_working_day']) {
            $this->add($items, 'salary', 'earning', 'auto', 'days', 1, $dayRate, $date);
        } elseif ($type === 'daily') {
            if (in_array($status, ['present', 'on_leave'], true)) {
                $this->add($items, 'days_worked', 'earning', 'auto', 'days', 1, $dayRate, $date);
            } elseif ($status === 'absent' && $leave) {
                // Only the leave part of an otherwise absent day is payable.
                $share = ($row['leave_minutes'] ?? 0) / max(1, $row['schedule_minutes']);
                $this->add($items, 'days_worked', 'earning', 'auto', 'days', $share, $dayRate * $share, $date);
            }
        } elseif ($type === 'hourly') {
            if (in_array($status, ['present', 'incomplete'], true) && $row['worked_minutes'] !== null) {
                $inside = max(0, $row['worked_minutes'] - ($row['extra_minutes'] ?? 0) - ($row['early_arrival_minutes'] ?? 0));
                $this->add($items, 'hours_worked', 'earning', 'auto', 'minutes', $inside, $hourRate * $inside / 60, $date);
            }
            if ($status === 'holiday' && $row['scheduled_working_day']) {
                $this->add($items, 'holiday_pay', 'earning', 'auto', 'minutes', $row['schedule_minutes'],
                    $hourRate * $row['schedule_minutes'] / 60, $date);
            }
        }

        // Leave: monthly and daily are paid for the day above, so the unpaid parts come off; hourly is paid only the paid part.
        if ($leave) {
            $covered = $leave['covered_units'] / 1_000_000;
            $unpaid = $leave['unpaid_units'] / 1_000_000;
            $percent = (int) $leave['pay_percent'];
            if ($type === 'hourly') {
                $share = $leave['day_units'] > 0 ? $leave['covered_units'] / $leave['day_units'] : 0;
                $minutes = ($row['leave_minutes'] ?? 0) * $share * $percent / 100;
                $this->add($items, 'paid_leave', 'earning', 'auto', 'minutes', $minutes, $hourRate * $minutes / 60, $date);
            } else {
                if ($unpaid > 0) {
                    $this->add($items, 'unpaid_leave', 'deduction', 'auto', 'days', $unpaid, $dayRate * $unpaid, $date);
                }
                if ($percent < 100 && $covered > 0) {
                    $share = $covered * (100 - $percent) / 100;
                    $this->add($items, 'partial_pay_leave', 'deduction', 'auto', 'days', $share, $dayRate * $share, $date);
                }
            }
        }

        // Overtime: capped post-shift minutes on working days, or the full worked span on a rest day / holiday.
        // Never pay more OT than minutes actually worked (guards stale days that credited end→out without a late arrival).
        if (in_array($status, ['day_off', 'holiday'], true)) {
            $overtime = (int) ($row['worked_minutes'] ?? 0);
        } else {
            $overtime = (int) ($row['extra_minutes'] ?? 0);
            if ($row['worked_minutes'] !== null) {
                $overtime = min($overtime, (int) $row['worked_minutes']);
            } elseif ($overtime > 0) {
                $overtime = 0;
            }
        }
        if ($overtime > 0) {
            $this->add($items, 'overtime', 'earning', 'auto', 'minutes', $overtime,
                $hourRate * $overtime / 60 * $row['overtime_multiplier'], $date);
        }

        // Proposed deductions (company manager decides). Hourly pay already reflects time not worked.
        if ($type !== 'hourly') {
            if (($row['late_minutes'] ?? 0) > 0) {
                $this->add($items, 'late', 'deduction', 'proposed', 'minutes', $row['late_minutes'], $hourRate * $row['late_minutes'] / 60, $date);
            }
            if (($row['early_minutes'] ?? 0) > 0) {
                $this->add($items, 'early_exit', 'deduction', 'proposed', 'minutes', $row['early_minutes'], $hourRate * $row['early_minutes'] / 60, $date);
            }
            if ($type === 'monthly' && $status === 'absent' && ($row['required_minutes'] ?? 0) > 0) {
                $this->add($items, 'absence', 'deduction', 'proposed', 'minutes', $row['required_minutes'],
                    $hourRate * $row['required_minutes'] / 60, $date);
            }
        }
    }

    private function add(array &$items, string $kind, string $category, string $origin, string $unit, float $quantity, float $amount, string $date): void
    {
        $items[$kind] ??= ['category' => $category, 'origin' => $origin, 'unit' => $unit, 'quantity' => 0.0, 'amount' => 0.0, 'details' => []];
        $items[$kind]['quantity'] += $quantity;
        $items[$kind]['amount'] += $amount;
        $items[$kind]['details'][] = ['date' => $date, 'quantity' => round($quantity, 4), 'amount' => $amount];
    }

    /** Contracts that ever applied during the period; a contract applies until its end or termination date. */
    private function contracts(Employee $employee, string $from, string $to): Collection
    {
        return EmploymentContract::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['active', 'terminated', 'expired'])
            ->whereDate('start_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->orderBy('start_date')->orderBy('id')->get();
    }

    private function contractOn(Collection $contracts, string $date): ?EmploymentContract
    {
        return $contracts->filter(function (EmploymentContract $c) use ($date) {
            $end = collect([$c->end_date, $c->terminated_at])->filter()
                ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->min();

            return $c->start_date->toDateString() <= $date && ($end === null || $end >= $date);
        })->last();
    }

    private function issueList(array $issues): array
    {
        $messages = ['no_contract' => 'لا يوجد عقد ساري براتب وعملة لأيام من الشهر.', 'mixed_currency' => 'عقود الشهر بعملات مختلفة؛ يلزم قرار يدوي.'];

        return collect($issues)->map(fn ($detail, $code) => ['code' => $code, 'message' => $messages[$code], 'detail' => $detail, 'blocking' => true])
            ->values()->all();
    }
}
