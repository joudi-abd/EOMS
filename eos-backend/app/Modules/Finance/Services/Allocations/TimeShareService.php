<?php

namespace App\Modules\Finance\Services\Allocations;

use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\PayrollTimeShare;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employees' monthly time shares on projects (spec §5.9-4, D13): the default basis of the payroll allocation (#17).
 * Each employee's shares total at most 100%; the rest of the salary stays general. A month's shares are frozen while
 * an approved, non-reversed payroll allocation exists for it.
 */
class TimeShareService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit, private ProjectService $projects) {}

    /**
     * The month's shares grouped per employee.
     *
     * @return array{year: int, month: int, locked: bool, employees: list<array{employee_id: int, employee_name: ?string, total: string,
     *     shares: list<array{project_id: int, project_name: ?string, percentage: string}>}>}
     */
    public function month(int $year, int $month): array
    {
        $rows = PayrollTimeShare::query()->where('year', $year)->where('month', $month)->orderBy('employee_id')->orderBy('project_id')->get();
        $employees = Employee::query()->whereIn('id', $rows->pluck('employee_id')->unique())->get()->keyBy('id');
        $projects = $this->projects->query()->whereIn('id', $rows->pluck('project_id')->unique())->pluck('name', 'id');

        return ['year' => $year, 'month' => $month, 'locked' => $this->locked($year, $month),
            'employees' => $rows->groupBy('employee_id')->map(fn ($shares, $employeeId) => [
                'employee_id' => (int) $employeeId, 'employee_name' => $employees->get($employeeId)?->full_name,
                'total' => Money::sum($shares->map(fn (PayrollTimeShare $s) => (string) $s->percentage)),
                'shares' => $shares->map(fn (PayrollTimeShare $s) => ['project_id' => $s->project_id, 'project_name' => $projects->get($s->project_id),
                    'percentage' => Money::of((string) $s->percentage)])->values()->all(),
            ])->values()->all()];
    }

    /**
     * Replaces the shares of the employees sent (an empty `shares` clears an employee); other employees keep theirs.
     *
     * @param  list<array{employee_id: int, shares: list<array{project_id: int, percentage: string|int|float}>}>  $employees
     */
    public function set(int $year, int $month, array $employees, User $actor): array
    {
        DB::transaction(function () use ($year, $month, $employees, $actor) {
            $this->assertEditable($year, $month);
            $projectIds = collect($employees)->flatMap(fn (array $e) => array_column($e['shares'], 'project_id'))->map(fn ($id) => (int) $id)->unique();
            $open = $this->projects->query()->whereIn('id', $projectIds)->where('status', '!=', 'closed')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $known = Employee::query()->whereIn('id', array_column($employees, 'employee_id'))->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_values($employees) as $i => $employee) {
                if (! in_array((int) $employee['employee_id'], $known, true)) {
                    throw ValidationException::withMessages(["employees.$i.employee_id" => ['الموظف غير موجود.']]);
                }
                $total = '0';
                $seen = [];
                foreach (array_values($employee['shares']) as $j => $share) {
                    if (! in_array((int) $share['project_id'], $open, true)) {
                        throw ValidationException::withMessages(["employees.$i.shares.$j.project_id" => ['المشروع غير موجود أو مغلق.']]);
                    }
                    if (isset($seen[(int) $share['project_id']])) {
                        throw ValidationException::withMessages(["employees.$i.shares.$j.project_id" => ['المشروع مكرر لهذا الموظف.']]);
                    }
                    $seen[(int) $share['project_id']] = true;
                    $total = Money::add($total, Money::of($share['percentage']));
                }
                if (Money::cmp($total, '100') > 0) {
                    throw ValidationException::withMessages(["employees.$i.shares" => ['مجموع نسب الموظف '.$total.'% يتجاوز 100%.']]);
                }
            }
            foreach ($employees as $employee) {
                $old = PayrollTimeShare::query()->where('year', $year)->where('month', $month)->where('employee_id', $employee['employee_id'])
                    ->orderBy('project_id')->lockForUpdate()->get(['project_id', 'percentage'])->toArray();
                PayrollTimeShare::query()->where('year', $year)->where('month', $month)->where('employee_id', $employee['employee_id'])->delete();
                foreach ($employee['shares'] as $share) {
                    PayrollTimeShare::create(['year' => $year, 'month' => $month, 'employee_id' => (int) $employee['employee_id'],
                        'project_id' => (int) $share['project_id'], 'percentage' => Money::round(Money::of($share['percentage']), 4),
                        'created_by' => $actor->id, 'updated_by' => $actor->id]);
                }
                $this->audit->log('finance.time_shares_set', null, ['employee_id' => $employee['employee_id'], 'shares' => $old],
                    ['employee_id' => $employee['employee_id'], 'year' => $year, 'month' => $month, 'shares' => $employee['shares']], $actor->id);
            }
        }, self::TX_ATTEMPTS);

        return $this->month($year, $month);
    }

    /**
     * Copies the previous month's shares to every employee who has none in this month (projects since closed are
     * skipped). Returns the month and how many employees were copied.
     */
    public function copyPrevious(int $year, int $month, User $actor): array
    {
        $copied = DB::transaction(function () use ($year, $month, $actor) {
            $this->assertEditable($year, $month);
            $previous = CarbonImmutable::create($year, $month, 1)->subMonth();
            $source = PayrollTimeShare::query()->where('year', $previous->year)->where('month', $previous->month)->orderBy('id')->get();
            if ($source->isEmpty()) {
                throw ValidationException::withMessages(['month' => ['لا توجد نسب وقت في الشهر السابق.']]);
            }
            $taken = PayrollTimeShare::query()->where('year', $year)->where('month', $month)->lockForUpdate()->pluck('employee_id')->unique()->all();
            $open = $this->projects->query()->whereIn('id', $source->pluck('project_id')->unique())->where('status', '!=', 'closed')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $employees = [];
            foreach ($source as $share) {
                if (in_array($share->employee_id, $taken, true) || ! in_array($share->project_id, $open, true)) {
                    continue;
                }
                PayrollTimeShare::create(['year' => $year, 'month' => $month, 'employee_id' => $share->employee_id, 'project_id' => $share->project_id,
                    'percentage' => $share->percentage, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                $employees[$share->employee_id] = true;
            }
            $this->audit->log('finance.time_shares_copied', null, null, ['year' => $year, 'month' => $month,
                'from' => $previous->format('Y-m'), 'employees' => array_keys($employees)], $actor->id);

            return count($employees);
        }, self::TX_ATTEMPTS);

        return [...$this->month($year, $month), 'copied' => $copied];
    }

    /**
     * The month's shares for the allocation, rows locked: employee id => list of [project id, percentage].
     *
     * @return array<int, list<array{0: int, 1: string}>>
     */
    public function sharesFor(int $year, int $month): array
    {
        $shares = [];
        foreach (PayrollTimeShare::query()->where('year', $year)->where('month', $month)->orderBy('employee_id')->orderBy('project_id')
            ->lockForUpdate()->get() as $share) {
            $shares[$share->employee_id][] = [$share->project_id, Money::of((string) $share->percentage)];
        }

        return $shares;
    }

    public function locked(int $year, int $month): bool
    {
        return Allocation::query()->where('kind', 'payroll')->where('year', $year)->where('month', $month)->where('status', 'approved')->exists();
    }

    private function assertEditable(int $year, int $month): void
    {
        abort_if($this->locked($year, $month), 409, 'نُفّذ توزيع الرواتب لهذا الشهر؛ اعكسه أولاً لتعديل النسب.');
    }
}
