<?php

namespace App\Modules\Finance\Services\Advances;

use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee salary advances (D17, entry #14) and their monthly installments. The advance and its schedule are created
 * when the advance voucher posts and cancelled when it is reversed (refused once an installment was deducted).
 * Payroll (phase 7) reads the due installments, marks them deducted on a run, releases them when a run is returned,
 * and may postpone one when it exceeds the net salary (R18).
 */
class EmployeeAdvanceService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private FinanceScope $scope, private AuditLogger $audit) {}

    /** The HR employee by id (Finance may use HR), or null. */
    public function employee(int $employeeId): ?Employee
    {
        return Employee::query()->find($employeeId);
    }

    /** The finance party linked to the employee (fin_parties.employee_id), or null. */
    public function partyOf(int $employeeId): ?Party
    {
        return Party::query()->where('employee_id', $employeeId)->first();
    }

    /**
     * Equal installments rounded down to the currency's decimals; the rounding remainder goes on the last one.
     * 1,000,000 SYP in 3 → 333,333 / 333,333 / 333,334.
     *
     * @return list<string>
     */
    public function split(string $amount, int $count, int $decimals): array
    {
        $base = Money::of(bcdiv(Money::of($amount), (string) $count, $decimals));
        $amounts = array_fill(0, $count - 1, $base);
        $amounts[] = Money::sub($amount, Money::mul($base, (string) ($count - 1), $decimals));

        return $amounts;
    }

    /** "2026-11" + n months. */
    public static function addMonths(string $month, int $months): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->addMonthsNoOverflow($months)->format('Y-m');
    }

    /** After the advance voucher posts: the advance and its schedule (meta.installments_count from meta.start_month). */
    public function createFromVoucher(Voucher $voucher, ?int $actorId): EmployeeAdvance
    {
        $meta = $voucher->meta ?? [];
        $count = (int) $meta['installments_count'];
        $decimals = (int) Currency::query()->whereKey($voucher->currency_id)->value('decimals');
        $advance = EmployeeAdvance::create([
            'employee_id' => $voucher->employee_id, 'party_id' => $voucher->party_id, 'voucher_id' => $voucher->id, 'amount' => $voucher->amount,
            'currency_id' => $voucher->currency_id, 'installments_count' => $count, 'start_month' => $meta['start_month'], 'status' => 'active',
            'notes' => $voucher->description, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);
        foreach ($this->split((string) $voucher->amount, $count, $decimals) as $i => $amount) {
            EmployeeAdvanceInstallment::create(['advance_id' => $advance->id, 'due_month' => self::addMonths($meta['start_month'], $i),
                'amount' => $amount, 'status' => 'pending', 'created_by' => $actorId, 'updated_by' => $actorId]);
        }
        $this->audit->log('finance.advance_created', $advance, null, $advance->getAttributes(), $actorId);

        return $advance;
    }

    /**
     * After the advance voucher is reversed: the advance is cancelled. Refused (and the whole reversal rolled back)
     * once any installment was deducted from a salary — the payroll run must be returned first.
     */
    public function cancelFromVoucher(Voucher $voucher, ?int $actorId): void
    {
        $advance = EmployeeAdvance::query()->where('voucher_id', $voucher->id)->lockForUpdate()->first();
        if ($advance === null) {
            return;
        }
        if (EmployeeAdvanceInstallment::query()->where('advance_id', $advance->id)->where('status', 'deducted')->exists()) {
            throw ValidationException::withMessages(['voucher' => ['لا تُعكس السلفة بعد خصم أحد أقساطها من الراتب؛ أعد كشف الرواتب أولاً.']]);
        }
        $old = $advance->only('status');
        $advance->update(['status' => 'cancelled', 'updated_by' => $actorId]);
        $this->audit->log('finance.advance_cancelled', $advance, $old, ['status' => 'cancelled'], $actorId);
    }

    /**
     * Pending installments of active advances due in `$month` or earlier, oldest first.
     *
     * @return Collection<int, array{id: int, advance_id: int, due_month: string, amount: string, currency_id: int, currency: string}>
     */
    public function installmentsDue(int $employeeId, string $month): Collection
    {
        return EmployeeAdvanceInstallment::query()
            ->join('fin_employee_advances as a', 'a.id', '=', 'fin_employee_advance_installments.advance_id')
            ->join('sys_currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('a.employee_id', $employeeId)->where('a.status', 'active')
            ->where('fin_employee_advance_installments.status', 'pending')->where('fin_employee_advance_installments.due_month', '<=', $month)
            ->orderBy('fin_employee_advance_installments.due_month')->orderBy('fin_employee_advance_installments.id')
            ->get(['fin_employee_advance_installments.id', 'fin_employee_advance_installments.advance_id', 'fin_employee_advance_installments.due_month',
                'fin_employee_advance_installments.amount', 'a.currency_id', 'c.code as currency'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'advance_id' => (int) $row->advance_id, 'due_month' => $row->due_month,
                'amount' => Money::of((string) $row->amount), 'currency_id' => (int) $row->currency_id, 'currency' => $row->currency])
            ->values();
    }

    /**
     * Installments deducted by a payroll run. Each must be pending on an active advance; an advance whose remaining
     * installments are all deducted becomes `settled`.
     *
     * @param  list<int>  $installmentIds
     */
    public function markDeducted(array $installmentIds, int $payRunId): void
    {
        $ids = array_values(array_unique(array_map('intval', $installmentIds)));
        sort($ids);
        if ($ids === []) {
            return;
        }
        DB::transaction(function () use ($ids, $payRunId) {
            $installments = EmployeeAdvanceInstallment::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $advanceIds = $installments->pluck('advance_id')->unique()->sort()->values()->all();
            $advances = EmployeeAdvance::query()->whereIn('id', $advanceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $id) {
                $installment = $installments->firstWhere('id', $id);
                if ($installment === null || $installment->status !== 'pending' || $advances[$installment->advance_id]->status !== 'active') {
                    throw ValidationException::withMessages(['installments' => ["القسط #{$id} غير مستحق للخصم."]]);
                }
            }
            EmployeeAdvanceInstallment::query()->whereIn('id', $ids)->update(['status' => 'deducted', 'pay_run_id' => $payRunId, 'updated_at' => now()]);
            foreach ($advances as $advance) {
                $deducted = $installments->where('advance_id', $advance->id)->pluck('id')->all();
                $this->audit->log('finance.advance_installments_deducted', $advance, ['status' => 'pending'],
                    ['status' => 'deducted', 'installment_ids' => $deducted, 'pay_run_id' => $payRunId]);
                $this->syncStatus($advance);
            }
        }, self::TX_ATTEMPTS);
    }

    /** A payroll run was returned or not approved: its deducted installments are pending again. */
    public function releaseRun(int $payRunId): void
    {
        DB::transaction(function () use ($payRunId) {
            $installments = EmployeeAdvanceInstallment::query()->where('pay_run_id', $payRunId)->where('status', 'deducted')
                ->orderBy('id')->lockForUpdate()->get();
            if ($installments->isEmpty()) {
                return;
            }
            EmployeeAdvanceInstallment::query()->whereIn('id', $installments->pluck('id'))
                ->update(['status' => 'pending', 'pay_run_id' => null, 'updated_at' => now()]);
            $advances = EmployeeAdvance::query()->whereIn('id', $installments->pluck('advance_id')->unique())->orderBy('id')->lockForUpdate()->get();
            foreach ($advances as $advance) {
                $released = $installments->where('advance_id', $advance->id)->pluck('id')->all();
                $this->audit->log('finance.advance_installments_released', $advance, ['status' => 'deducted', 'pay_run_id' => $payRunId],
                    ['status' => 'pending', 'installment_ids' => $released]);
                $this->syncStatus($advance);
            }
        }, self::TX_ATTEMPTS);
    }

    /**
     * R18 option: the installment moves to the month after the advance's last installment. The original row stays
     * as `postponed` (with the reason) and a new pending installment of the same amount is added.
     */
    public function postpone(int $installmentId, string $reason, User $actor): void
    {
        DB::transaction(function () use ($installmentId, $reason, $actor) {
            $installment = EmployeeAdvanceInstallment::query()->lockForUpdate()->findOrFail($installmentId);
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($installment->advance_id);
            if ($installment->status !== 'pending' || $advance->status !== 'active') {
                throw ValidationException::withMessages(['installment' => ['يؤجَّل القسط غير المخصوم من سلفة قائمة فقط.']]);
            }
            $last = (string) EmployeeAdvanceInstallment::query()->where('advance_id', $advance->id)->max('due_month');
            $moved = EmployeeAdvanceInstallment::create(['advance_id' => $advance->id, 'due_month' => self::addMonths($last, 1),
                'amount' => $installment->amount, 'status' => 'pending', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $installment->update(['status' => 'postponed', 'postponed_reason' => $reason, 'updated_by' => $actor->id]);
            $this->audit->log('finance.advance_installment_postponed', $advance,
                ['installment_id' => $installment->id, 'due_month' => $installment->due_month, 'status' => 'pending'],
                ['installment_id' => $moved->id, 'due_month' => $moved->due_month, 'status' => 'pending', 'reason' => $reason], $actor->id);
        }, self::TX_ATTEMPTS);
    }

    /**
     * What the employee still owes on active advances, per currency code (pending installments only).
     *
     * @return array<string, string>
     */
    public function outstanding(int $employeeId): array
    {
        $rows = EmployeeAdvanceInstallment::query()
            ->join('fin_employee_advances as a', 'a.id', '=', 'fin_employee_advance_installments.advance_id')
            ->join('sys_currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('a.employee_id', $employeeId)->where('a.status', 'active')->where('fin_employee_advance_installments.status', 'pending')
            ->orderBy('c.code')->get(['c.code', 'fin_employee_advance_installments.amount']);
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->code] = Money::add($totals[$row->code] ?? '0', (string) $row->amount);
        }

        return $totals;
    }

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        return $this->scoped($user)
            ->with(['currency:id,code,decimals', 'party:id,name', 'voucher:id,number,branch_id,voucher_date,status'])
            ->withSum(['installments as remaining' => fn ($q) => $q->where('status', 'pending')], 'amount')
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->whereHas('currency', fn ($c) => $c->where('code', strtoupper($v))))
            ->when($filters['currency_id'] ?? null, fn ($q, $v) => $q->where('currency_id', $v))
            ->orderByDesc('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)))
            ->through(fn (EmployeeAdvance $a) => [...$a->toArray(), 'remaining' => Money::of((string) ($a->remaining ?? '0'))]);
    }

    /** One advance with its schedule and what remains, for someone who may read the voucher's branch. */
    public function show(EmployeeAdvance $advance, User $user): array
    {
        $this->assertReach($advance, $user, 'finance.view');
        $advance->load(['currency:id,code,decimals', 'party:id,name', 'voucher:id,number,branch_id,voucher_date,status',
            'installments' => fn ($q) => $q->orderBy('due_month')->orderBy('id')]);
        $pending = $advance->installments->where('status', 'pending');
        $deducted = $advance->installments->where('status', 'deducted');

        return [...$advance->toArray(), 'deducted_total' => Money::sum($deducted->map(fn ($i) => (string) $i->amount)),
            'remaining' => Money::sum($pending->map(fn ($i) => (string) $i->amount)), 'next_due_month' => $pending->first()?->due_month];
    }

    public function assertReach(EmployeeAdvance $advance, User $user, string $permission): void
    {
        $branchId = Voucher::query()->whereKey($advance->voucher_id)->value('branch_id');
        $this->scope->assertBranch($user, $permission, $branchId === null ? null : (int) $branchId);
    }

    /**
     * T15: per employee and currency, what remains on active advances and the next installment; and the installments
     * due in the coming `$months` months (from the current one), per month and currency.
     *
     * @return array{balances: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    public function report(User $user, int $months = 3): array
    {
        $current = now()->format('Y-m');
        $until = self::addMonths($current, max(1, $months) - 1);
        $advances = $this->scoped($user)->where('status', 'active')
            ->with(['currency:id,code', 'party:id,name', 'installments' => fn ($q) => $q->where('status', 'pending')->orderBy('due_month')->orderBy('id')])
            ->orderBy('employee_id')->get();

        $balances = [];
        $upcoming = [];
        foreach ($advances as $advance) {
            $key = $advance->employee_id.'|'.$advance->currency_id;
            $remaining = Money::sum($advance->installments->map(fn ($i) => (string) $i->amount));
            $next = $advance->installments->first();
            $row = $balances[$key] ?? ['employee_id' => $advance->employee_id, 'employee_name' => $advance->party?->name, 'party_id' => $advance->party_id,
                'currency_id' => $advance->currency_id, 'currency' => $advance->currency?->code, 'advances' => 0, 'outstanding' => '0',
                'next_due_month' => null, 'next_amount' => '0'];
            $row['advances']++;
            $row['outstanding'] = Money::add($row['outstanding'], $remaining);
            if ($next !== null && ($row['next_due_month'] === null || $next->due_month <= $row['next_due_month'])) {
                $row['next_amount'] = $next->due_month === $row['next_due_month'] ? Money::add($row['next_amount'], (string) $next->amount) : Money::of((string) $next->amount);
                $row['next_due_month'] = $next->due_month;
            }
            $balances[$key] = $row;
            foreach ($advance->installments->where('due_month', '<=', $until) as $installment) {
                $month = max($installment->due_month, $current); // overdue pending installments fall due now
                $slot = $month.'|'.$advance->currency_id;
                $upcoming[$slot] ??= ['due_month' => $month, 'currency_id' => $advance->currency_id, 'currency' => $advance->currency?->code,
                    'installments' => 0, 'amount' => '0'];
                $upcoming[$slot]['installments']++;
                $upcoming[$slot]['amount'] = Money::add($upcoming[$slot]['amount'], (string) $installment->amount);
            }
        }
        ksort($upcoming);

        return ['balances' => array_values($balances), 'upcoming' => array_values($upcoming)];
    }

    /** Advances whose voucher is in a branch the user reads (R6). */
    private function scoped(User $user): Builder
    {
        return EmployeeAdvance::query()->whereHas('voucher', fn (Builder $q) => $this->scope->apply($q, $user, 'finance.view'));
    }

    /** active ↔ settled: settled once nothing is pending. */
    private function syncStatus(EmployeeAdvance $advance): void
    {
        $pending = EmployeeAdvanceInstallment::query()->where('advance_id', $advance->id)->where('status', 'pending')->exists();
        $status = $pending ? 'active' : 'settled';
        if ($advance->status !== 'cancelled' && $advance->status !== $status) {
            $advance->update(['status' => $status]);
        }
    }
}
