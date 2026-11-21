<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\BranchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What Payroll asks of Finance (Payroll depends on Finance, never the reverse): post the salary accrual of an
 * approved run (#15), post a salary payment from a box (#16), reverse a payment's voucher when it is voided, and check
 * the account a salary component posts to.
 * Called inside Payroll's own transactions.
 */
class PayrollFinanceService
{
    public function __construct(private VoucherService $vouchers, private PostingService $posting, private BranchService $branches,
        private SystemAccounts $roles) {}

    /**
     * Accrual (#15) on the last day of the run's month, at the head office, one group of lines per employee in the
     * contract currency:
     * - earnings: Dr the item's account (the component's expense account, else `salaries_expense`); a project-linked item
     *   Dr `project_costs` (project, the `loaded_payroll` cost category);
     * - deductions: an advance installment Cr `employee_advances` (party = the advance's party); a project-linked one
     *   Cr `project_costs`; any other Cr its account (else `salaries_expense`);
     * - the net Cr `salaries_payable`.
     * Every line carries the employee. Amounts are rounded to the currency's decimals; the payable is the net rounded
     * the same way the payment rounds it, and the rounding left over goes on the general salary expense line, so the
     * payable clears exactly. An employee line may give `earnings` / `deductions` instead of `items` (all general).
     * Returns null when nobody earns anything.
     *
     * @param  list<array{employee_id: int, currency: string, memo?: ?string, earnings?: string, deductions?: string,
     *     items?: list<array{category: string, amount: string|float, account_id?: ?int, project_id?: ?int, installment_id?: ?int, label?: ?string}>}>  $lines
     */
    public function accrue(string $month, array $lines, User $actor, array $meta = []): ?Voucher
    {
        $rows = [];
        foreach ($lines as $line) {
            array_push($rows, ...$this->accrualRows($line));
        }
        if ($rows === []) {
            return null;
        }
        $branch = $this->branches->headOffice() ?? throw ValidationException::withMessages(['branch_id' => ['عيّن الشركة الرئيسية في الفروع أولاً.']]);

        return DB::transaction(function () use ($month, $rows, $actor, $meta, $branch) {
            $voucher = $this->vouchers->createInternal([
                'type' => 'payroll_accrual', 'voucher_date' => CarbonImmutable::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString(),
                'branch_id' => $branch->id, 'description' => 'استحقاق رواتب '.$month, 'meta' => $meta + ['month' => $month], 'lines' => $rows,
            ], $actor);

            return $this->posting->approve($voucher, $actor);
        }, 3);
    }

    /**
     * An account a salary component may post to: active, postable, not a cash box nor another role's account; an earning
     * goes to an expense account, a deduction to an expense, liability or revenue account. 422 on `$field` otherwise.
     */
    public function assertComponentAccount(int $accountId, string $kind, string $field = 'expense_account_id'): void
    {
        $account = Account::query()->find($accountId);
        $types = $kind === 'earning' ? ['expense'] : ['expense', 'liability', 'revenue'];
        if ($account === null || ! $account->is_active || ! $account->is_postable || $account->is_system
            || CashBox::query()->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([$field => ['اختر حساباً فعّالاً يقبل القيود، وليس حساب صندوق.']]);
        }
        if (! in_array($account->type, $types, true)) {
            throw ValidationException::withMessages([$field => [$kind === 'earning' ? 'حساب بند الاستحقاق يجب أن يكون حساب مصروف.'
                : 'حساب بند الاستقطاع يجب أن يكون حساب مصروف أو التزام أو إيراد.']]);
        }
        if ($this->roles->isRoleAccount($account->id, ['salaries_expense'])) {
            throw ValidationException::withMessages([$field => ['هذا الحساب مربوط بدور إلزامي آخر؛ اختر حساباً غيره.']]);
        }
    }

    /**
     * One employee's accrual lines, ready for the voucher (see accrue()).
     *
     * @return list<array<string, mixed>>
     */
    private function accrualRows(array $line): array
    {
        $currency = Currency::query()->where('code', strtoupper($line['currency']))->first()
            ?? throw ValidationException::withMessages(['currency' => ['العملة '.$line['currency'].' غير موجودة.']]);
        $items = $line['items'] ?? [
            ['category' => 'earning', 'amount' => $line['earnings'] ?? '0'],
            ['category' => 'deduction', 'amount' => $line['deductions'] ?? '0'],
        ];
        $employeeId = (int) $line['employee_id'];
        $earned = Money::sum(array_map(fn (array $i) => $i['category'] === 'earning' ? Money::of($i['amount']) : '0', $items));
        $deducted = Money::sum(array_map(fn (array $i) => $i['category'] === 'deduction' ? Money::of($i['amount']) : '0', $items));
        if (! Money::isPositive($earned)) {
            return [];
        }
        $net = Money::sub($earned, $deducted);
        if (Money::isNegative($net)) {
            throw ValidationException::withMessages(['run' => ['صافي راتب الموظف #'.$employeeId.' سالب؛ لا يُرحَّل الاستحقاق.']]);
        }

        // Same account, side and dimensions → one line; amounts summed before rounding.
        $groups = [];
        foreach ($items as $item) {
            $amount = Money::of($item['amount']);
            if (! Money::isPositive($amount)) {
                continue;
            }
            $side = $item['category'] === 'earning' ? 'debit' : 'credit';
            $dims = ['employee_id' => $employeeId];
            if (! empty($item['installment_id'])) {
                $account = $this->roles->id('employee_advances');
                $dims['party_id'] = $this->advancePartyOf((int) $item['installment_id'], $employeeId);
            } elseif (! empty($item['project_id'])) {
                $account = $this->roles->id('project_costs');
                $dims += ['project_id' => (int) $item['project_id'], 'project_cost_category_id' => $this->loadedPayrollCategory()];
            } else {
                $account = ! empty($item['account_id']) ? (int) $item['account_id'] : $this->roles->id('salaries_expense');
            }
            $key = $side.'|'.$account.'|'.json_encode($dims);
            $groups[$key] ??= ['side' => $side, 'account_id' => $account, 'dims' => $dims, 'amount' => '0', 'memo' => $item['label'] ?? null];
            $groups[$key]['amount'] = Money::add($groups[$key]['amount'], $amount);
            if (($groups[$key]['memo'] ?? null) !== ($item['label'] ?? null)) {
                $groups[$key]['memo'] = null;
            }
        }
        $decimals = (int) $currency->decimals;
        $payable = Money::round($net, $decimals);
        $balance = '0';
        foreach ($groups as $key => $group) {
            $groups[$key]['amount'] = Money::round($group['amount'], $decimals);
            $balance = $group['side'] === 'debit' ? Money::add($balance, $groups[$key]['amount']) : Money::sub($balance, $groups[$key]['amount']);
        }
        // Rounding left over between the rounded lines and the rounded net: on the general salary expense line.
        $difference = Money::sub($payable, $balance);
        if (! Money::isZero($difference)) {
            $expense = $this->roles->id('salaries_expense');
            $target = collect($groups)->filter(fn ($g) => $g['side'] === 'debit' && $g['account_id'] === $expense && ! isset($g['dims']['project_id']))->keys()->first()
                ?? collect($groups)->filter(fn ($g) => $g['side'] === 'debit')->sortByDesc(fn ($g) => (float) $g['amount'])->keys()->first();
            $groups[$target]['amount'] = Money::add($groups[$target]['amount'], $difference);
        }

        $rows = [];
        foreach ($groups as $group) {
            if (! Money::isPositive($group['amount'])) {
                continue;
            }
            $rows[] = ['account_id' => $group['account_id'], 'currency_id' => $currency->id, ...$group['dims'], $group['side'] => $group['amount'],
                'memo' => $group['memo'] ?? ($group['side'] === 'debit' ? 'استحقاقات' : 'استقطاعات')];
        }
        if (Money::isPositive($payable)) {
            $rows[] = ['account_id' => $this->roles->id('salaries_payable'), 'currency_id' => $currency->id, 'employee_id' => $employeeId,
                'credit' => $payable, 'memo' => $line['memo'] ?? 'صافي الراتب'];
        }

        return $rows;
    }

    /** The party the advance was paid to (its subledger), else the employee's linked party. */
    private function advancePartyOf(int $installmentId, int $employeeId): ?int
    {
        $installment = EmployeeAdvanceInstallment::query()->with('advance:id,employee_id,party_id')->find($installmentId);
        if ($installment === null || $installment->advance === null || $installment->advance->employee_id !== $employeeId) {
            throw ValidationException::withMessages(['installments' => ["القسط #{$installmentId} لا يخص هذا الموظف."]]);
        }

        return $installment->advance->party_id ?? Party::query()->where('employee_id', $employeeId)->value('id');
    }

    private function loadedPayrollCategory(): int
    {
        return (int) (ProjectCostCategory::query()->where('system_key', 'loaded_payroll')->value('id')
            ?? throw ValidationException::withMessages(['project_cost_category_id' => ['أضف تصنيف تكلفة "رواتب محمّلة" (loaded_payroll) أولاً.']]));
    }

    /**
     * A salary leaves a box: a posted payroll_payment voucher. The box must hold the paid currency (R19).
     *
     * Amounts may arrive as floats from Payroll's own arithmetic; they are normalised with Money::of().
     *
     * @param  array{box_id: int, employee_id: int, contract_currency: string, net: string|float, paid_currency: string, paid: string|float,
     *     converted: string|float, rate: string|float, date: string, description: string, meta?: array}  $data
     */
    public function pay(array $data, User $actor): Voucher
    {
        $box = CashBox::query()->find($data['box_id']);
        $paidCurrency = Currency::query()->where('code', strtoupper($data['paid_currency']))->first();
        if ($box === null || ! $box->is_active) {
            throw ValidationException::withMessages(['box_id' => ['الصندوق غير موجود أو غير فعّال.']]);
        }
        if ($paidCurrency === null || ! CashBoxCurrency::query()->where('cash_box_id', $box->id)->where('currency_id', $paidCurrency->id)->exists()) {
            throw ValidationException::withMessages(['box_id' => ['عملة الدفع غير مفعّلة في الصندوق المختار.']]);
        }

        return DB::transaction(function () use ($data, $actor, $box) {
            $voucher = $this->vouchers->createInternal([
                'type' => 'payroll_payment', 'voucher_date' => $data['date'], 'cash_box_id' => $box->id, 'employee_id' => $data['employee_id'],
                'currency' => $data['paid_currency'], 'amount' => Money::of($data['paid']),
                'counter_currency' => $data['contract_currency'], 'counter_amount' => Money::of($data['net']), 'rate' => Money::of($data['rate']),
                'description' => $data['description'], 'meta' => ($data['meta'] ?? []) + ['converted' => Money::of($data['converted'])],
            ], $actor);

            return $this->posting->approve($voucher, $actor);
        }, 3);
    }

    /** Reverses a payroll voucher (a voided payment), dated in an open month. */
    public function reverse(int $voucherId, string $date, string $reason, User $actor): Voucher
    {
        return $this->posting->reverse(Voucher::query()->findOrFail($voucherId), $date, $reason, $actor);
    }
}
