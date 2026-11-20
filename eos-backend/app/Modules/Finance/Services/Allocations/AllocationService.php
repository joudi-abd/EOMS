<?php

namespace App\Modules\Finance\Services\Allocations;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\AllocationLine;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\PeriodService;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\BranchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly allocation to projects (D13, spec §6.3): payroll (#17) and overhead (#18). Everything is read from the
 * ledger, never from Payroll's tables:
 * - payroll pool = per employee and currency, the general salary cost of the month's posted payroll accruals: lines on
 *   expense accounts carrying the employee (salaries_expense and component accounts, net of deductions credited to
 *   them), excluding project-linked items already on project_costs;
 * - overhead pool = per currency, expense lines of the month and branch in categories flagged
 *   `is_overhead_allocatable` that are not on a project.
 * Methods: time_shares (payroll), percentages, manual_amounts, proportional_direct_cost (overhead: each branch project's
 * billable project costs of the month, in USD). Rounding never loses a cent: what one pool distributes sums to it
 * exactly, the remainder on the last project (§14-10).
 * Posting: an `allocation` voucher dated the month's last day: Dr project_costs (project, loaded_payroll or
 * loaded_overhead, employee for payroll) / Cr payroll_allocation_contra or overhead_allocation_contra, per currency.
 * One approved, non-reversed allocation per kind, month and branch; reversing its voucher frees the period.
 */
class AllocationService
{
    private const TX_ATTEMPTS = 3;

    public const KINDS = ['payroll' => 'توزيع الرواتب على المشاريع', 'overhead' => 'توزيع المصاريف العامة على المشاريع'];

    public const METHODS = ['payroll' => ['time_shares', 'percentages', 'manual_amounts'],
        'overhead' => ['proportional_direct_cost', 'percentages', 'manual_amounts']];

    public function __construct(private SystemAccounts $roles, private VoucherService $vouchers, private PostingService $posting,
        private PeriodService $periods, private FinanceScope $scope, private BranchService $branches, private AuditLogger $audit,
        private TimeShareService $shares, private ProjectService $projects) {}

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        return $this->scope->apply(Allocation::query(), $user, 'finance.allocations')
            ->with('voucher:id,number,status,voucher_date')
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->where('year', $v))
            ->when($filters['month'] ?? null, fn ($q, $v) => $q->where('month', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
    }

    public function show(Allocation $allocation, User $user): array
    {
        $this->scope->assertBranch($user, 'finance.allocations', $allocation->branch_id);

        return $this->present($allocation);
    }

    /** What an allocation would post, without posting it. */
    public function preview(array $data, User $user): array
    {
        $branchId = $this->branchId($data);
        $this->scope->assertBranch($user, 'finance.allocations', $branchId);

        return DB::transaction(function () use ($data, $branchId) {
            $result = $this->compute($data, $branchId);
            $existing = $this->approvedFor($data['kind'], (int) $data['year'], (int) $data['month'], $branchId);
            if ($existing !== null) {
                $result['warnings'][] = 'يوجد توزيع معتمد لهذه الفترة والفرع (#'.$existing->id.')؛ اعكسه قبل اعتماد توزيع جديد.';
            }

            return [...$result, 'existing_allocation_id' => $existing?->id];
        }, self::TX_ATTEMPTS);
    }

    /** Computes, records and posts the allocation in one transaction. */
    public function create(array $data, User $actor): array
    {
        $branchId = $this->branchId($data);
        $this->scope->assertBranch($actor, 'finance.allocations', $branchId);

        return DB::transaction(function () use ($data, $branchId, $actor) {
            $year = (int) $data['year'];
            $month = (int) $data['month'];
            [, $to] = $this->periods->bounds($year, $month);
            // The period row is the first lock of every posting: two allocations of the same month queue up here.
            $this->periods->ensureOpen($to);
            abort_if($this->approvedFor($data['kind'], $year, $month, $branchId) !== null, 409,
                'يوجد توزيع معتمد لهذه الفترة والفرع؛ اعكس سنده أولاً.');
            $result = $this->compute($data, $branchId);
            if ($result['lines'] === []) {
                throw ValidationException::withMessages(['lines' => ['لا يوجد ما يُوزَّع على المشاريع لهذه الفترة.']]);
            }

            $allocation = Allocation::create(['kind' => $data['kind'], 'year' => $year, 'month' => $month, 'branch_id' => $branchId,
                'method' => $data['method'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($result['lines'] as $line) {
                AllocationLine::create(['allocation_id' => $allocation->id, 'project_id' => $line['project_id'], 'currency_id' => $line['currency_id'],
                    'employee_id' => $line['employee_id'], 'percentage' => $line['percentage'], 'amount' => $line['amount']]);
            }
            $voucher = $this->vouchers->createInternal(['type' => 'allocation', 'voucher_date' => $to, 'branch_id' => $branchId,
                'description' => self::KINDS[$data['kind']].' '.$this->periods->label($year, $month),
                'meta' => ['allocation_id' => $allocation->id, 'kind' => $data['kind'], 'method' => $data['method']],
                'lines' => $this->voucherLines($data['kind'], $result['lines'])], $actor);
            $voucher = $this->posting->approve($voucher, $actor);
            $allocation->update(['status' => 'approved', 'voucher_id' => $voucher->id]);
            $this->audit->log('finance.allocation_approved', $allocation, null, ['kind' => $allocation->kind, 'period' => $this->periods->label($year, $month),
                'branch_id' => $branchId, 'method' => $allocation->method, 'voucher' => $voucher->number], $actor->id);

            return [...$this->present($allocation->refresh()), 'warnings' => array_values(array_unique([...$result['warnings'], ...$voucher->warnings]))];
        }, self::TX_ATTEMPTS);
    }

    /** The allocation voucher was reversed (AllocationPoster::afterReverse): the period may be allocated again. */
    public function markReversed(Voucher $voucher, ?int $actorId): void
    {
        $allocation = Allocation::query()->where('voucher_id', $voucher->id)->lockForUpdate()->first();
        if ($allocation === null || $allocation->status !== 'approved') {
            return;
        }
        $allocation->update(['status' => 'reversed', 'updated_by' => $actorId]);
        $this->audit->log('finance.allocation_reversed', $allocation, ['status' => 'approved'], ['status' => 'reversed', 'voucher' => $voucher->number], $actorId);
    }

    /**
     * @return array{kind: string, year: int, month: int, branch_id: int, method: string, pool: list<array<string, mixed>>, pool_base_total: string,
     *     lines: list<array<string, mixed>>, allocated: list<array<string, mixed>>, unallocated: list<array<string, mixed>>, warnings: list<string>}
     */
    private function compute(array $data, int $branchId): array
    {
        $kind = $data['kind'];
        $method = $data['method'];
        if (! in_array($method, self::METHODS[$kind] ?? [], true)) {
            throw ValidationException::withMessages(['method' => ['طريقة التوزيع لا تناسب هذا النوع.']]);
        }
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        [$from, $to] = $this->periods->bounds($year, $month);
        $currencies = Currency::query()->get()->keyBy('id');
        $warnings = [];

        if ($kind === 'payroll') {
            $sources = $this->payrollSources($from, $to, $branchId); // [employee_id][currency_id] => amount
            $pool = [];
            foreach ($sources as $byCurrency) {
                foreach ($byCurrency as $currencyId => $amount) {
                    $pool[$currencyId] = Money::add($pool[$currencyId] ?? '0', $amount);
                }
            }
            $poolBase = $this->payrollBase($from, $to, $branchId);
        } else {
            [$pool, $poolBase] = $this->overheadPool($from, $to, $branchId);
        }
        $pool = array_filter($pool, fn (string $amount) => Money::isPositive($amount));
        ksort($pool);
        if ($pool === []) {
            $warnings[] = $kind === 'payroll' ? 'لا توجد رواتب عامة مستحقة مرحّلة لهذا الشهر والفرع.' : 'لا توجد مصاريف عامة قابلة للتوزيع لهذا الشهر والفرع.';
        }

        $lines = match ($method) {
            'time_shares' => $this->byTimeShares($sources ?? [], $year, $month, $currencies),
            'percentages' => $this->byPercentages($pool, $data['lines'] ?? [], $kind, $branchId, $currencies),
            'manual_amounts' => $this->byAmounts($pool, $data['lines'] ?? [], $kind, $branchId, $currencies),
            'proportional_direct_cost' => $this->byDirectCost($pool, $from, $to, $branchId, $currencies, $warnings),
        };
        $lines = array_values(array_filter($lines, fn (array $l) => Money::isPositive($l['amount'])));

        $allocated = [];
        foreach ($lines as $line) {
            $allocated[$line['currency_id']] = Money::add($allocated[$line['currency_id']] ?? '0', $line['amount']);
        }
        $names = $this->projects->query()->whereIn('id', array_column($lines, 'project_id'))->pluck('name', 'id');
        $employees = Employee::query()->whereIn('id', array_filter(array_column($lines, 'employee_id')))->get()->keyBy('id');
        $code = fn (int $id) => $currencies->get($id)?->code;

        return [
            'kind' => $kind, 'year' => $year, 'month' => $month, 'branch_id' => $branchId, 'method' => $method,
            'pool' => array_map(fn ($id, $amount) => ['currency_id' => $id, 'currency' => $code($id), 'amount' => $amount], array_keys($pool), $pool),
            'pool_base_total' => $poolBase,
            'lines' => array_map(fn (array $l) => [...$l, 'currency' => $code($l['currency_id']), 'project_name' => $names->get($l['project_id']),
                'employee_name' => $l['employee_id'] === null ? null : $employees->get($l['employee_id'])?->full_name], $lines),
            'allocated' => array_map(fn ($id) => ['currency_id' => $id, 'currency' => $code($id), 'amount' => $allocated[$id] ?? '0'], array_keys($pool)),
            'unallocated' => array_map(fn ($id, $amount) => ['currency_id' => $id, 'currency' => $code($id), 'amount' => Money::sub($amount, $allocated[$id] ?? '0')],
                array_keys($pool), $pool),
            'warnings' => $warnings,
        ];
    }

    /**
     * Per employee and currency: the general salary cost of the month's posted payroll accruals in the branch.
     *
     * @return array<int, array<int, string>>
     */
    private function payrollSources(string $from, string $to, int $branchId): array
    {
        $sources = [];
        foreach ($this->payrollLines($from, $to, $branchId)->groupBy(['employee_id', 'currency_id'])
            ->selectRaw('employee_id, currency_id, SUM(debit) - SUM(credit) as net')->orderBy('employee_id')->orderBy('currency_id')->get() as $row) {
            $net = Money::round(Money::of((string) $row->net), Money::SCALE);
            if (Money::isPositive($net)) {
                $sources[(int) $row->employee_id][(int) $row->currency_id] = $net;
            }
        }

        return $sources;
    }

    private function payrollBase(string $from, string $to, int $branchId): string
    {
        return Money::round(Money::of((string) $this->payrollLines($from, $to, $branchId)->sum(DB::raw('base_debit - base_credit'))), Money::SCALE);
    }

    private function payrollLines(string $from, string $to, int $branchId)
    {
        $accruals = Voucher::query()->select('id')->where('type', 'payroll_accrual')->where('status', 'approved')
            ->where('branch_id', $branchId)->whereBetween('voucher_date', [$from, $to]);
        $entries = JournalEntry::query()->select('id')->where('source_type', 'voucher')->whereIn('source_id', $accruals)->whereNull('reversal_of_id');
        $excluded = array_filter([$this->roles->mappedId('project_costs'), $this->roles->mappedId('payroll_allocation_contra')]);

        return JournalLine::query()->whereIn('journal_entry_id', $entries)->whereNotNull('employee_id')->whereNull('project_id')
            ->whereIn('account_id', Account::query()->select('id')->where('type', 'expense'))->whereNotIn('account_id', $excluded);
    }

    /** @return array{0: array<int, string>, 1: string} per currency, and the USD equivalent at the lines' own rates */
    private function overheadPool(string $from, string $to, int $branchId): array
    {
        $rows = JournalLine::query()
            ->whereIn('expense_category_id', ExpenseCategory::query()->select('id')->where('is_overhead_allocatable', true))
            ->whereNull('project_id')->whereIn('account_id', Account::query()->select('id')->where('type', 'expense'))
            ->where('branch_id', $branchId)->whereBetween('entry_date', [$from, $to])
            ->groupBy('currency_id')->selectRaw('currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')->get();
        $pool = [];
        $base = '0';
        foreach ($rows as $row) {
            $pool[(int) $row->currency_id] = Money::round(Money::of((string) $row->net), Money::SCALE);
            $base = Money::add($base, Money::round(Money::of((string) $row->base_net), Money::SCALE));
        }

        return [$pool, $base];
    }

    /** #17 by time shares: each employee's cost × their project shares; what is not shared stays general. */
    private function byTimeShares(array $sources, int $year, int $month, Collection $currencies): array
    {
        $shares = $this->shares->sharesFor($year, $month);
        $lines = [];
        foreach ($sources as $employeeId => $byCurrency) {
            $employeeShares = $shares[$employeeId] ?? [];
            if ($employeeShares === []) {
                continue;
            }
            foreach ($byCurrency as $currencyId => $amount) {
                foreach ($this->split($amount, $employeeShares, (int) $currencies->get($currencyId)->decimals) as [$projectId, $percentage, $share]) {
                    $lines[] = ['project_id' => $projectId, 'currency_id' => $currencyId, 'employee_id' => $employeeId, 'percentage' => $percentage, 'amount' => $share];
                }
            }
        }

        return $lines;
    }

    /** A percentage per project (total ≤ 100) of each currency's pool. */
    private function byPercentages(array $pool, array $input, string $kind, int $branchId, Collection $currencies): array
    {
        $weights = [];
        $total = '0';
        foreach (array_values($input) as $i => $row) {
            if (! isset($row['project_id'], $row['percentage']) || ! Money::isPositive(Money::of($row['percentage']))) {
                throw ValidationException::withMessages(["lines.$i.percentage" => ['أدخل المشروع ونسبته.']]);
            }
            if (in_array((int) $row['project_id'], array_column($weights, 0), true)) {
                throw ValidationException::withMessages(["lines.$i.project_id" => ['المشروع مكرر.']]);
            }
            $weights[] = [(int) $row['project_id'], Money::of($row['percentage'])];
            $total = Money::add($total, Money::of($row['percentage']));
        }
        if ($weights === []) {
            throw ValidationException::withMessages(['lines' => ['أدخل نسبة لكل مشروع.']]);
        }
        if (Money::cmp($total, '100') > 0) {
            throw ValidationException::withMessages(['lines' => ['مجموع النسب '.$total.'% يتجاوز 100%.']]);
        }
        $this->assertProjects(array_column($weights, 0), $kind, $branchId);
        $lines = [];
        foreach ($pool as $currencyId => $amount) {
            foreach ($this->split($amount, $weights, (int) $currencies->get($currencyId)->decimals) as [$projectId, $percentage, $share]) {
                $lines[] = ['project_id' => $projectId, 'currency_id' => $currencyId, 'employee_id' => null, 'percentage' => $percentage, 'amount' => $share];
            }
        }

        return $lines;
    }

    /** An amount per project and currency; per currency at most the pool. */
    private function byAmounts(array $pool, array $input, string $kind, int $branchId, Collection $currencies): array
    {
        $lines = [];
        $totals = [];
        foreach (array_values($input) as $i => $row) {
            $currency = isset($row['currency']) ? $currencies->firstWhere('code', strtoupper((string) $row['currency'])) : $currencies->get($row['currency_id'] ?? 0);
            if ($currency === null || ! isset($pool[$currency->id])) {
                throw ValidationException::withMessages(["lines.$i.currency" => ['لا يوجد وعاء توزيع بهذه العملة.']]);
            }
            if (! isset($row['project_id'], $row['amount']) || ! Money::isPositive(Money::of($row['amount']))) {
                throw ValidationException::withMessages(["lines.$i.amount" => ['أدخل المشروع والمبلغ.']]);
            }
            $amount = Money::round(Money::of($row['amount']), (int) $currency->decimals);
            $totals[$currency->id] = Money::add($totals[$currency->id] ?? '0', $amount);
            $lines[] = ['project_id' => (int) $row['project_id'], 'currency_id' => $currency->id, 'employee_id' => null, 'percentage' => null, 'amount' => $amount];
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['أدخل مبلغاً لكل مشروع.']]);
        }
        foreach ($totals as $currencyId => $total) {
            if (Money::cmp($total, $pool[$currencyId]) > 0) {
                throw ValidationException::withMessages(['lines' => ['مجموع المبالغ بعملة '.$currencies->get($currencyId)->code.' ('.$total.') يتجاوز الوعاء ('.$pool[$currencyId].').']]);
            }
        }
        $this->assertProjects(array_column($lines, 'project_id'), $kind, $branchId);

        return $lines;
    }

    /** #18 proportional: the branch's projects with billable direct costs in the month, weighted by those costs in USD. */
    private function byDirectCost(array $pool, string $from, string $to, int $branchId, Collection $currencies, array &$warnings): array
    {
        $costs = $this->roles->mappedId('project_costs');
        $rows = JournalLine::query()->where('account_id', $costs ?? 0)->whereBetween('entry_date', [$from, $to])
            ->whereIn('project_cost_category_id', ProjectCostCategory::query()->select('id')->where('is_billable', true))
            ->whereIn('project_id', $this->projects->query()->select('id')->where('branch_id', $branchId)->where('status', '!=', 'closed'))
            ->groupBy('project_id')->selectRaw('project_id, SUM(base_debit) - SUM(base_credit) as base_net')->orderBy('project_id')->get();
        $weights = [];
        foreach ($rows as $row) {
            $weight = Money::round(Money::of((string) $row->base_net), Money::SCALE);
            if (Money::isPositive($weight)) {
                $weights[] = [(int) $row->project_id, $weight];
            }
        }
        if ($weights === []) {
            if ($pool !== []) {
                $warnings[] = 'لا توجد تكاليف مباشرة لمشاريع هذا الفرع في الشهر؛ اختر النسب أو المبالغ.';
            }

            return [];
        }
        $total = Money::sum(array_column($weights, 1));
        $lines = [];
        foreach ($pool as $currencyId => $amount) {
            foreach ($this->split($amount, $weights, (int) $currencies->get($currencyId)->decimals, $total) as [$projectId, , $share, $weight]) {
                $lines[] = ['project_id' => $projectId, 'currency_id' => $currencyId, 'employee_id' => null,
                    'percentage' => Money::round(Money::div(Money::mul($weight, '100', 8), $total, 8), 4), 'amount' => $share];
            }
        }

        return $lines;
    }

    /**
     * Splits `$amount` by weights, rounded to the currency's decimals. With `$whole` null the weights are percentages
     * and the share distributed is amount × Σweights / 100; otherwise all of `$amount` is distributed over `$whole`.
     * The distributed share is exact: the last project takes the rounding remainder (§14-10).
     *
     * @param  list<array{0: int, 1: string}>  $weights
     * @return list<array{0: int, 1: string, 2: string, 3: string}> [project id, weight, amount, weight]
     */
    private function split(string $amount, array $weights, int $decimals, ?string $whole = null): array
    {
        $sum = Money::sum(array_column($weights, 1));
        $target = $whole === null ? Money::round(Money::div(Money::mul($amount, $sum, 8), '100', 8), $decimals) : Money::round($amount, $decimals);
        $denominator = $whole ?? $sum;
        $result = [];
        $given = '0';
        $last = count($weights) - 1;
        foreach (array_values($weights) as $i => [$projectId, $weight]) {
            $share = $i === $last ? Money::sub($target, $given)
                : Money::round(Money::div(Money::mul($target, $weight, 8), $denominator, 8), $decimals);
            if (Money::isNegative($share)) {
                throw ValidationException::withMessages(['lines' => ['تعذّر توزيع المبلغ بهذه النسب.']]);
            }
            $given = Money::add($given, $share);
            $result[] = [$projectId, $weight, $share, $weight];
        }

        return $result;
    }

    /** Projects exist and are open; overhead goes to the branch's own projects. */
    private function assertProjects(array $projectIds, string $kind, int $branchId): void
    {
        $ids = array_values(array_unique(array_map('intval', $projectIds)));
        $projects = $this->projects->query()->whereIn('id', $ids)->get(['id', 'name', 'status', 'branch_id']);
        if ($projects->count() !== count($ids)) {
            throw ValidationException::withMessages(['lines' => ['أحد المشاريع غير موجود.']]);
        }
        foreach ($projects as $project) {
            if ($project->status === 'closed') {
                throw ValidationException::withMessages(['lines' => ["المشروع {$project->name} مغلق."]]);
            }
            if ($kind === 'overhead' && $project->branch_id !== $branchId) {
                throw ValidationException::withMessages(['lines' => ["المشروع {$project->name} من فرع آخر؛ المصاريف العامة توزَّع على مشاريع الفرع."]]);
            }
        }
    }

    /** Dr project_costs per allocation line; Cr the contra per currency (and per employee for payroll). */
    private function voucherLines(string $kind, array $lines): array
    {
        $category = (int) (ProjectCostCategory::query()->where('system_key', $kind === 'payroll' ? 'loaded_payroll' : 'loaded_overhead')->value('id')
            ?? throw ValidationException::withMessages(['project_cost_category_id' => ['أضف تصنيف التكلفة المحمّلة ('.($kind === 'payroll' ? 'loaded_payroll' : 'loaded_overhead').') أولاً.']]));
        $costs = $this->roles->id('project_costs');
        $contra = $this->roles->id($kind === 'payroll' ? 'payroll_allocation_contra' : 'overhead_allocation_contra');
        $memo = $kind === 'payroll' ? 'رواتب محمّلة' : 'مصاريف عامة موزّعة';
        $rows = [];
        $credits = [];
        foreach ($lines as $line) {
            $rows[] = ['account_id' => $costs, 'currency_id' => $line['currency_id'], 'project_id' => $line['project_id'],
                'project_cost_category_id' => $category, 'employee_id' => $line['employee_id'], 'debit' => $line['amount'], 'memo' => $memo];
            $key = $line['currency_id'].'|'.($line['employee_id'] ?? 0);
            $credits[$key] ??= ['account_id' => $contra, 'currency_id' => $line['currency_id'], 'employee_id' => $line['employee_id'], 'credit' => '0', 'memo' => $memo];
            $credits[$key]['credit'] = Money::add($credits[$key]['credit'], $line['amount']);
        }

        return [...$rows, ...array_values($credits)];
    }

    private function approvedFor(string $kind, int $year, int $month, int $branchId): ?Allocation
    {
        return Allocation::query()->where('kind', $kind)->where('year', $year)->where('month', $month)->where('branch_id', $branchId)
            ->where('status', 'approved')->lockForUpdate()->first();
    }

    private function branchId(array $data): int
    {
        if (! empty($data['branch_id'])) {
            return (int) $data['branch_id'];
        }
        // Payroll accrues at the head office; overhead names its branch.
        $head = $this->branches->headOffice();
        if ($data['kind'] !== 'payroll' || $head === null) {
            throw ValidationException::withMessages(['branch_id' => ['حدّد الفرع.']]);
        }

        return $head->id;
    }

    private function present(Allocation $allocation): array
    {
        $allocation->load(['lines' => fn ($q) => $q->orderBy('id'), 'voucher:id,number,status,voucher_date,journal_entry_id,reversal_entry_id']);
        $currencies = Currency::query()->pluck('code', 'id');
        $names = $this->projects->query()->whereIn('id', $allocation->lines->pluck('project_id')->unique())->pluck('name', 'id');
        $employees = Employee::query()->whereIn('id', $allocation->lines->pluck('employee_id')->filter()->unique())->get()->keyBy('id');
        $totals = [];
        foreach ($allocation->lines as $line) {
            $code = $currencies->get($line->currency_id);
            $totals[$code] = Money::add($totals[$code] ?? '0', (string) $line->amount);
        }

        return [...$allocation->toArray(), 'label' => self::KINDS[$allocation->kind] ?? $allocation->kind, 'totals' => $totals,
            'lines' => $allocation->lines->map(fn (AllocationLine $l) => [...$l->toArray(), 'currency' => $currencies->get($l->currency_id),
                'project_name' => $names->get($l->project_id), 'employee_name' => $l->employee_id === null ? null : $employees->get($l->employee_id)?->full_name])->all()];
    }
}
