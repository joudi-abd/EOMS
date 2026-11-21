<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Services\PayrollFinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Notifications\HrNotification;
use App\Modules\HR\Services\EmploymentPeriodService;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Settings\Services\CurrencyService;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Monthly pay run workflow: HR prepares the draft and enters bonuses/deductions without deciding them
 * (payroll.manage) and sends it to finance; finance reviews every proposed or manual item and every
 * locked-month adjustment, then approves (payroll.approve), which locks the month; payments are then
 * recorded one per line, individually or through a Sham Cash file (payroll.pay).
 * Changes that later touch a locked month come back as adjustment items in the next draft.
 */
class PayRunService
{
    /** Whole-transaction retries on deadlocks and serialization conflicts (MariaDB 1020, PostgreSQL 40001/40P01). */
    private const TX_ATTEMPTS = 3;

    /** How far back locked months are re-checked for adjustments. */
    private const ADJUSTMENT_LOOKBACK_MONTHS = 12;

    /** Items recalculation rebuilds; manual items (HR) are kept. */
    private const COMPUTED_ORIGINS = ['auto', 'proposed', 'adjustment', 'recurring', 'advance'];

    public function __construct(private PayrollCalculator $calculator, private AuditLogger $audit, private ExchangeRateService $rates,
        private PayrollFinanceService $finance, private CurrencyService $currencies, private EmployeeAdvanceService $advances,
        private SalaryComponentService $components) {}

    public function create(string $month, User $actor): PayRun
    {
        $today = app(EmploymentPeriodService::class)->today();
        if ($month >= substr($today, 0, 7)) {
            throw ValidationException::withMessages(['month' => ['يُنشأ كشف الشهر بعد انتهائه فقط.']]);
        }

        // Creation and first calculation are one unit: a calculation error (e.g. no attendance policy) must not leave
        // an empty run behind that blocks a retry with "a run already exists".
        return DB::transaction(function () use ($month, $actor) {
            if (PayRun::query()->where('month', $month)->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['month' => ['يوجد كشف لهذا الشهر مسبقاً.']]);
            }
            $run = PayRun::create(['month' => $month, 'prepared_by' => $actor->id]);
            $this->audit->log('payroll.run_created', $run, null, ['month' => $month], $actor->id);

            return $this->recalculate($run, $actor);
        }, self::TX_ATTEMPTS);
    }

    /** Recomputes automatic, proposed and adjustment items; manual items entered by finance are kept. */
    public function recalculate(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'draft');
            $employees = $this->employeesFor($run->month);
            // People who left can still have retroactive changes on a locked month: they get a line only for those.
            $former = $this->formerEmployees($run->month, $employees->pluck('id'));
            $employees = $employees->concat($former);
            PayRunLine::query()->where('pay_run_id', $run->id)->whereNotIn('employee_id', $employees->pluck('id'))
                ->whereDoesntHave('items', fn ($q) => $q->where('origin', 'manual'))->delete();
            // A line kept only for HR's manual items no longer earns salary for the month: drop its computed items so
            // a stale salary is never carried into approval and payment.
            foreach (PayRunLine::query()->where('pay_run_id', $run->id)->whereNotIn('employee_id', $employees->pluck('id'))->get() as $orphan) {
                $orphan->items()->whereIn('origin', self::COMPUTED_ORIGINS)->delete();
                $orphan->fill(['working_days' => 0, 'attendance_ready' => true, 'blocked_days' => 0, 'issues' => [],
                    'source_hash' => null])->save();
                $this->totals($orphan);
            }
            foreach ($employees as $employee) {
                $result = $this->calculator->forEmployee($employee, $run->month);
                $line = PayRunLine::query()->firstOrNew(['pay_run_id' => $run->id, 'employee_id' => $employee->id]);
                $previous = $result['currency_code'] === null ? $this->lastLockedLine($employee->id, $run->month) : null;
                $line->fill([
                    'contract_id' => $result['contract_id'] ?? $previous?->contract_id, 'pay_type' => $result['pay_type'] ?? $previous?->pay_type,
                    'currency_code' => $result['currency_code'] ?? $previous?->currency_code,
                    'rate' => $result['rate'], 'working_days' => $result['working_days'],
                    'attendance_ready' => $result['attendance_ready'], 'blocked_days' => $result['blocked_days'],
                    'issues' => $result['issues'], 'source_hash' => $this->fingerprint($result),
                ])->save();
                // Finance decisions on adjustments survive recalculation while the adjustment itself is unchanged.
                $decided = $line->items()->where('origin', 'adjustment')->where('status', '!=', 'proposed')->get()
                    ->keyBy(fn (PayRunItem $i) => $this->adjustmentKey($i->details));
                $line->items()->whereIn('origin', self::COMPUTED_ORIGINS)->delete();
                foreach ([...$result['items'], ...$result['recurring']] as $item) {
                    $this->storeItem($line, $item, $item['origin'] === 'proposed' ? 'proposed' : 'applied');
                }
                $this->addAdjustments($line, $employee, $run->month, $decided);
                if ($former->contains('id', $employee->id) && ! $line->items()->exists()) {
                    $line->delete();

                    continue;
                }
                if (! $former->contains('id', $employee->id)) {
                    $this->addInstallments($line, $run->month);
                }
                $this->totals($line);
                $this->flagAdvancesOverNet($line);
            }
            $run->update(['calculated_at' => now(), 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_calculated', $run, null, ['lines' => $employees->count()], $actor->id);

            return $run->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function addManualItem(PayRun $run, array $data, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($run, $data, $actor) {
            $run = $this->lock($run, 'draft');
            $line = PayRunLine::query()->where('pay_run_id', $run->id)->where('employee_id', $data['employee_id'])->firstOrFail();
            $component = $this->manualComponent($data);
            $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
            $this->components->assertProject($component, $projectId);
            [$amount, $details] = $this->manualAmount($component, $line, $data);
            $item = $line->items()->create([
                'kind' => $component->code, 'component_id' => $component->id, 'project_id' => $projectId, 'category' => $component->kind,
                // HR proposes; finance approves, changes or waives it when reviewing the run.
                // It counts as zero until then.
                'origin' => 'manual', 'proposed_amount' => $amount, 'amount' => 0, 'status' => 'proposed',
                'reason' => $data['reason'], 'details' => $details, 'created_by' => $actor->id,
            ]);
            $this->totals($line);
            $this->touch($run);
            $this->audit->log('payroll.manual_item_added', $item, null, $item->getAttributes(), $actor->id);

            return $item;
        }, self::TX_ATTEMPTS);
    }

    public function removeManualItem(PayRunItem $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'draft');
            abort_unless($item->origin === 'manual', 409, 'يمكن حذف البنود اليدوية فقط.');
            $this->audit->log('payroll.manual_item_removed', $item, $item->getAttributes(), null, $actor->id);
            $item->delete();
            $this->totals($line);
            $this->touch($run);
        }, self::TX_ATTEMPTS);
    }

    /** Finance accepts or rejects an adjustment for a locked month while reviewing the submitted run. */
    public function decideAdjustment(PayRunItem $item, bool $accept, string $reason, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($item, $accept, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'submitted');
            abort_unless($item->origin === 'adjustment', 409, 'البند ليس تسوية.');
            $item->update(['status' => $accept ? 'approved' : 'rejected', 'amount' => $accept ? $item->proposed_amount : 0,
                'reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now()]);
            $this->totals($line);
            $this->touch($run);
            $this->audit->log('payroll.adjustment_decided', $item, null, ['status' => $item->status], $actor->id);

            return $item->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function submit(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'draft');
            abort_if($run->calculated_at === null, 409, 'احسب الكشف أولاً.');
            // Submit refuses stale fingerprints/adjustments, but not unfinished attendance — finance may review first.
            $this->lockApprovalSources($run);
            $lines = PayRunLine::query()->where('pay_run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
            $problems = $this->approvalSourceProblems($run, $lines, requireAttendanceReady: false);
            if ($problems !== []) {
                throw ValidationException::withMessages(['run' => $problems]);
            }
            $run->update(['status' => 'submitted', 'submitted_at' => now(), 'return_note' => null, 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_submitted', $run, null, null, $actor->id);
            $this->notify('payroll.approve', 'payroll.run_submitted', 'كشف رواتب '.$run->month.' مرفوع من الموارد البشرية بانتظار اعتماد المالية.', $run);

            return $run->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function returnToDraft(PayRun $run, string $note, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $note, $actor) {
            $run = $this->lock($run, 'submitted');
            $run->update(['status' => 'draft', 'return_note' => $note, 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_returned', $run, null, ['note' => $note], $actor->id);
            $this->notify('payroll.manage', 'payroll.run_returned', 'أُعيد كشف رواتب '.$run->month.' للتعديل: '.$note, $run);

            return $run->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** Finance accepts, changes (between 0 and the proposal) or waives a system-proposed deduction or an item HR entered. */
    public function reviewProposed(PayRunItem $item, string $decision, ?float $amount, string $reason, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($item, $decision, $amount, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'submitted');
            abort_unless(in_array($item->origin, ['proposed', 'manual'], true), 409, 'البند ليس مقترحاً للمراجعة.');
            abort_if($item->created_by !== null && $item->created_by === $actor->id, 403, 'لا يراجع أحد بنداً أدخله بنفسه.');
            $proposed = (float) $item->proposed_amount;
            $value = match ($decision) {
                'approve' => $proposed,
                'waive' => 0.0,
                'modify' => $amount ?? throw ValidationException::withMessages(['amount' => ['أدخل المبلغ المعدّل.']]),
            };
            if ($value < 0 || $value > $proposed) {
                throw ValidationException::withMessages(['amount' => ['المبلغ المعدّل بين صفر والخصم المقترح.']]);
            }
            $item->update(['amount' => round($value, 2), 'status' => ['approve' => 'approved', 'waive' => 'waived', 'modify' => 'modified'][$decision],
                'reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now()]);
            $this->totals($line);
            $this->touch($run);
            $this->audit->log('payroll.deduction_reviewed', $item, null, ['status' => $item->status, 'amount' => $item->amount], $actor->id);

            return $item->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * R18: finance postpones an advance installment the run would deduct (typically when installments exceed the net
     * salary). The installment moves after the advance's last one (EmployeeAdvanceService::postpone) and leaves the run.
     */
    public function postponeInstallment(PayRunItem $item, string $reason, User $actor): PayRunLine
    {
        return DB::transaction(function () use ($item, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'submitted');
            $item = PayRunItem::query()->lockForUpdate()->findOrFail($item->id);
            abort_unless($item->origin === 'advance' && isset($item->details['installment_id']), 409, 'البند ليس قسط سلفة.');
            $this->advances->postpone((int) $item->details['installment_id'], $reason, $actor);
            $this->audit->log('payroll.installment_postponed', $item, $item->getAttributes(), ['reason' => $reason], $actor->id);
            $item->delete();
            $this->totals($line);
            $this->flagAdvancesOverNet($line->refresh());
            $this->touch($run);

            return $line->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * One decision for every item still awaiting review (optionally only some kinds, e.g. all lateness), so finance
     * need not open dozens of small deductions one by one. Each item is recorded as its own decision.
     *
     * @param  list<string>|null  $kinds
     * @param  list<int>|null  $employeeIds
     */
    public function reviewRemaining(PayRun $run, string $decision, ?array $kinds, string $reason, User $actor, ?array $employeeIds = null): int
    {
        return DB::transaction(function () use ($run, $decision, $kinds, $reason, $actor, $employeeIds) {
            $run = $this->lock($run, 'submitted');
            $lineIds = $employeeIds === null
                ? null
                : PayRunLine::query()->where('pay_run_id', $run->id)->whereIn('employee_id', $employeeIds)->pluck('id')->all();
            $items = $this->items($run)->whereIn('origin', ['proposed', 'manual'])->where('status', 'proposed')
                ->when($kinds !== null, fn ($q) => $q->whereIn('kind', $kinds))
                ->when($lineIds !== null, fn ($q) => $q->whereIn('pay_run_line_id', $lineIds))
                // Items a reviewer entered themselves stay for another reviewer.
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $actor->id))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->update(['amount' => $decision === 'approve' ? $item->proposed_amount : 0,
                    'status' => $decision === 'approve' ? 'approved' : 'waived', 'reason' => $reason,
                    'decided_by' => $actor->id, 'decided_at' => now()]);
                $this->audit->log('payroll.deduction_reviewed', $item, null, ['status' => $item->status, 'amount' => $item->amount, 'bulk' => true], $actor->id);
            }
            foreach (PayRunLine::query()->whereIn('id', $items->pluck('pay_run_line_id')->unique())->get() as $line) {
                $this->totals($line);
            }
            if ($items->isNotEmpty()) {
                $this->touch($run);
            }

            return $items->count();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Approval locks the month. It re-resolves the eligible roster, rechecks each line's attendance/contract
     * fingerprint, and re-prices locked-month adjustments against current sources. Any drift rejects approval
     * so HR must return the run to draft and recalculate. Every proposed item (system deductions, HR bonuses and
     * deductions, locked-month adjustments) needs an explicit finance decision first; nothing is accepted implicitly.
     */
    public function approve(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'submitted');
            abort_if($run->prepared_by === $actor->id, 403, 'لا يعتمد أحد كشفاً أنشأه بنفسه.');
            abort_if($this->items($run)->where('origin', 'manual')->where('created_by', $actor->id)->exists(), 403,
                'لا يعتمد أحد كشفاً أضاف إليه بنوداً بنفسه.');
            abort_if($this->items($run)->where('origin', 'adjustment')->where('status', 'proposed')->exists(), 409,
                'قرّر في تسويات الأشهر المقفلة قبل الاعتماد.');

            $this->lockApprovalSources($run);
            $lines = PayRunLine::query()->where('pay_run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
            $problems = $this->approvalSourceProblems($run, $lines);
            if ($problems !== []) {
                throw ValidationException::withMessages(['run' => $problems]);
            }
            $undecided = $this->items($run)->whereIn('origin', ['proposed', 'manual'])->where('status', 'proposed')->count();
            abort_if($undecided > 0, 409, "يوجد $undecided بنداً بلا قرار (خصومات مقترحة أو بنود الموارد البشرية)؛ اعتمدها أو عدّلها أو ألغها قبل اعتماد المسير.");

            foreach ($lines as $line) {
                $this->totals($line->refresh());
                if ((float) $line->net < 0) {
                    $advance = $line->items()->where('origin', 'advance')->exists() ? ' أقساط السلف تتجاوزه؛ أجّل قسطاً (R18).' : '';
                    throw ValidationException::withMessages(['run' => [Employee::find($line->employee_id)?->full_name.': صافي الراتب سالب.'.$advance]]);
                }
            }
            $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'version' => $run->version + 1]);
            // The approved salaries are owed: one posted accrual on the month's last day (finance #15), item by item.
            $accrual = $this->finance->accrue($run->month, $this->accrualLines($run, $lines), $actor, ['pay_run_id' => $run->id]);
            $run->update(['accrual_voucher_id' => $accrual?->id]);
            // The advance installments the run deducted are settled by it (R18, §5.9-2).
            $this->advances->markDeducted($this->installmentIds($run), $run->id);
            $this->audit->log('payroll.run_approved', $run, null, ['month' => $run->month, 'accrual_voucher_id' => $accrual?->id], $actor->id);
            $this->notify('payroll.manage', 'payroll.run_approved', 'اعتمدت المالية كشف رواتب '.$run->month.'.', $run);
            $this->notify('payroll.pay', 'payroll.run_approved', 'اعتُمد كشف رواتب '.$run->month.' ويمكن البدء بالصرف.', $run);

            return $run->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * One payment per line: converted at the payment-date rate, then rounded to the paid currency's rounding step.
     * Pass from_export_id when confirming that open Sham Cash file; otherwise a reserved line is refused.
     */
    public function recordPayment(PayRunLine $line, array $data, User $actor): PayPayment
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            // Same lock order as Sham Cash export/confirm: run → open exports → line.
            $run = PayRun::query()->lockForUpdate()->findOrFail($line->pay_run_id);
            $this->assertShamCashReservation($line->id, $run->id, isset($data['from_export_id']) ? (int) $data['from_export_id'] : null);
            $line = PayRunLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'approved', 409, 'يُسجَّل الدفع بعد اعتماد الكشف.');
            abort_if($line->payment()->exists(), 409, 'سُجّل الدفع لهذا الموظف مسبقاً.');
            abort_unless((float) $line->net > 0, 409, 'لا يوجد مبلغ مستحق.');
            $paidCurrency = strtoupper($data['currency']);
            ['rate' => $rate, 'converted' => $converted, 'paid' => $paid] = $this->quote($line, $paidCurrency, $data['paid_at']);
            // SYP pays in whole thousands: a tiny balance can round to nothing, which no box can pay out.
            if ($paid <= 0) {
                throw ValidationException::withMessages(['currency' => ['المبلغ المستحق بعد التقريب صفر بهذه العملة؛ ادفعه بعملة أخرى أو أضفه لكشف لاحق.']]);
            }
            // A bulk file fixes the amount when it is produced; a later rate change must not pay a different sum.
            if (isset($data['expected_amount']) && abs($paid - (float) $data['expected_amount']) >= 0.005) {
                throw ValidationException::withMessages(['currency' => ['تغيّر المبلغ المستحق أو سعر الصرف بعد إصدار الملف؛ ألغِ الملف وأصدر ملفاً جديداً.']]);
            }
            $wallet = null;
            if ($data['method'] === 'sham_cash') {
                // File confirm passes the wallet frozen in the export row; individual pay uses the employee record.
                $wallet = isset($data['wallet']) && $data['wallet'] !== ''
                    ? (string) $data['wallet']
                    : Employee::query()->whereKey($line->employee_id)->value('sham_cash_wallet');
                // Same rule as the bulk file: a Sham Cash wallet is exactly 16 digits.
                $wallet = preg_replace('/\s+/', '', (string) $wallet);
                if (preg_match('/^\d{16}$/', $wallet) !== 1) {
                    throw ValidationException::withMessages(['method' => ['لا توجد محفظة شام كاش صحيحة (16 رقماً) مسجّلة للموظف.']]);
                }
            }
            $payment = $line->payment()->create([
                'contract_currency' => $line->currency_code, 'net_amount' => $line->net, 'paid_currency' => $paidCurrency,
                'exchange_rate' => $rate, 'rate_date' => $data['paid_at'], 'converted_amount' => $converted,
                'paid_amount' => $paid, 'rounding_difference' => round($paid - $converted, 2), 'method' => $data['method'],
                'wallet' => $wallet, 'reference' => $data['reference'] ?? null, 'paid_at' => $data['paid_at'],
                'paid_by' => $actor->id, 'note' => $data['note'] ?? null,
            ]);
            // The salary leaves the chosen finance box: one posted payroll_payment voucher (finance #16).
            $voucher = $this->finance->pay(['box_id' => (int) $data['box_id'], 'employee_id' => $line->employee_id,
                'contract_currency' => $line->currency_code, 'net' => (string) $line->net, 'paid_currency' => $paidCurrency,
                'paid' => $paid, 'converted' => $converted, 'rate' => $rate, 'date' => $data['paid_at'],
                'description' => 'راتب '.$run->month.': '.Employee::find($line->employee_id)?->full_name,
                'meta' => ['pay_payment_id' => $payment->id, 'pay_run_id' => $run->id]], $actor);
            $payment->update(['finance_voucher_id' => $voucher->id]);
            $this->audit->log('payroll.payment_recorded', $payment, null, $payment->getAttributes(), $actor->id);

            return $payment;
        }, self::TX_ATTEMPTS);
    }

    /** A line in an open Sham Cash file is reserved for that file's confirm; individual pay must wait for cancel. */
    private function assertShamCashReservation(int $lineId, int $runId, ?int $fromExportId): void
    {
        $open = PayExport::query()->where('pay_run_id', $runId)
            ->whereNull('cancelled_at')->whereNull('confirmed_at')->orderBy('id')->lockForUpdate()->get();
        $holding = $open->first(fn (PayExport $export) => in_array($lineId, array_map('intval', $export->line_ids ?? []), true));
        if ($fromExportId !== null) {
            abort_unless($holding !== null && $holding->id === $fromExportId, 409, 'تأكيد الملف يدفع فقط سطوره المحجوزة في هذا الملف.');

            return;
        }
        abort_if($holding !== null, 409, 'الموظف مدرج في ملف شام كاش مفتوح؛ ألغِ الملف غير المنفَّذ أو أكّده قبل الدفع الفردي.');
    }

    /**
     * What a line pays in a currency on a date: converted at that day's rate, rounded to the currency's rounding step.
     *
     * @return array{rate: float, converted: float, paid: float}
     */
    public function quote(PayRunLine $line, string $currency, string $date): array
    {
        $rate = $this->rates->rateOn($line->currency_code, $currency, $date);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => ['لا يوجد سعر صرف مسجّل بتاريخ الدفع؛ أدخله في الإعدادات أولاً.']]);
        }
        $converted = round((float) $line->net * $rate, 2);
        // Each currency's rounding step from settings (SYP 1,000, USD 1).
        $step = (float) ($this->currencies->byCode($currency)?->rounding_step ?? 1);

        return ['rate' => (float) $rate, 'converted' => $converted, 'paid' => (float) (round($converted / $step) * $step)];
    }

    /** Voids a wrong payment: the salary returns to its finance box by reversal and the line can be paid again. */
    public function voidPayment(PayPayment $payment, string $date, string $reason, User $actor): PayPayment
    {
        return DB::transaction(function () use ($payment, $date, $reason, $actor) {
            $payment = PayPayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_if($payment->voided_at !== null, 409, 'الدفعة ملغاة مسبقاً.');
            if ($date < $payment->paid_at->format('Y-m-d')) {
                throw ValidationException::withMessages(['date' => ['تاريخ الإلغاء لا يسبق تاريخ الدفع.']]);
            }
            if ($payment->finance_voucher_id) {
                $this->finance->reverse($payment->finance_voucher_id, $date, 'إلغاء دفعة راتب: '.$reason, $actor);
            }
            $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->audit->log('payroll.payment_voided', $payment, null, ['reason' => $reason], $actor->id);

            return $payment->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** Re-prices earlier locked months and proposes the difference not yet settled by earlier adjustments. */
    private function addAdjustments(PayRunLine $line, Employee $employee, string $month, Collection $decided): void
    {
        ['issues' => $extraIssues, 'items' => $items] = $this->computeAdjustments($employee, $month, $line->currency_code);
        $issues = [...($line->issues ?? []), ...$extraIssues];
        foreach ($items as $item) {
            $previous = $decided->get($this->adjustmentKey($item['details']));
            if ($previous) {
                $line->items()->create([...$previous->only(['kind', 'component_id', 'category', 'origin', 'proposed_amount', 'amount',
                    'status', 'reason', 'decided_by', 'decided_at']), 'details' => $item['details']]);

                continue;
            }
            $this->storeItem($line, $item, 'proposed');
        }
        $line->update(['issues' => $issues]);
    }

    /**
     * Locked prior-month deltas and non-blocking notes that recalculate would attach for one employee.
     *
     * @return array{issues: list<array>, items: list<array>}
     */
    private function computeAdjustments(Employee $employee, string $month, ?string $lineCurrency): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->subMonths(self::ADJUSTMENT_LOOKBACK_MONTHS)->format('Y-m');
        $lockedLines = PayRunLine::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->where('status', 'approved')->where('month', '<', $month)->where('month', '>=', $from))
            ->with('run')->orderBy('id')->get();
        $issues = [];
        $items = [];
        foreach ($lockedLines as $locked) {
            $fresh = $this->calculator->forEmployee($employee, $locked->run->month);
            if (! $fresh['attendance_ready']) {
                // Not blocking: the change waits until HR re-approves that month's days.
                $issues[] = ['code' => 'locked_month_pending', 'message' => 'تغييرات قيد المراجعة على شهر مقفل '.$locked->run->month.'؛ ستظهر تسويتها بعد اعتماد الحضور.', 'detail' => $locked->run->month, 'blocking' => false];

                continue;
            }
            if ($lineCurrency !== null && $fresh['currency_code'] !== $lineCurrency) {
                $issues[] = ['code' => 'adjustment_currency', 'message' => 'عملة الشهر المقفل '.$locked->run->month.' تختلف عن عملة هذا الشهر؛ التسوية يدوية.', 'detail' => $locked->run->month, 'blocking' => false];

                continue;
            }
            $recomputed = collect($fresh['items'])->keyBy('kind');
            $original = $locked->items()->whereIn('origin', ['auto', 'proposed'])->get()->keyBy('kind');
            foreach ($recomputed->keys()->merge($original->keys())->unique() as $kind) {
                $freshItem = $recomputed->get($kind);
                $lockedItem = $original->get($kind);
                $new = $this->signed($freshItem['category'] ?? null, (float) ($freshItem['amount'] ?? 0));
                // Financial baseline is what was applied after finance review, not the system proposal.
                $applied = $this->signed($lockedItem?->category, (float) ($lockedItem?->amount ?? 0));
                $proposed = $this->signed($lockedItem?->category, (float) ($lockedItem?->proposed_amount ?? 0));
                $target = $this->adjustmentTarget($applied, $proposed, $new);
                $settled = $this->settledAdjustments($employee->id, $locked->run->month, $kind, $month);
                $delta = round($target - $applied - $settled, 2);
                if (abs($delta) < 0.01) {
                    continue;
                }
                $details = [
                    'source_month' => $locked->run->month, 'source_kind' => $kind,
                    'locked' => $applied, 'locked_proposed' => $proposed,
                    'recomputed' => $new, 'previously_settled' => $settled, 'signed_delta' => $delta,
                ];
                $items[] = [
                    'kind' => 'adjustment', 'category' => $delta > 0 ? 'earning' : 'deduction', 'origin' => 'adjustment',
                    'unit' => null, 'quantity' => null, 'amount' => abs($delta), 'details' => $details,
                ];
            }
        }

        return ['issues' => $issues, 'items' => $items];
    }

    /** Hold eligibility and locked-month rows still while approval rechecks sources. */
    private function lockApprovalSources(PayRun $run): void
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $run->month)->subMonths(self::ADJUSTMENT_LOOKBACK_MONTHS)->format('Y-m');
        $monthStart = $run->month.'-01';
        $monthEnd = CarbonImmutable::parse($monthStart)->endOfMonth()->toDateString();
        EmployeeServicePeriod::query()
            ->whereDate('start_date', '<=', $monthEnd)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $monthStart))
            ->orderBy('id')->lockForUpdate()->get();
        PayRun::query()->where('status', 'approved')->where('month', '<', $run->month)->where('month', '>=', $from)
            ->orderBy('id')->lockForUpdate()->get();
        PayRunLine::query()->whereIn('pay_run_id', PayRun::query()->where('status', 'approved')
            ->where('month', '<', $run->month)->where('month', '>=', $from)->select('id'))
            ->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Roster, fingerprints, and locked-month adjustments must still match the run.
     * Attendance readiness is required on approve; submit only rejects stale source hashes.
     *
     * @param  Collection<int, PayRunLine>  $lines
     * @return list<string>
     */
    private function approvalSourceProblems(PayRun $run, Collection $lines, bool $requireAttendanceReady = true): array
    {
        $problems = [];
        $inService = $this->employeesFor($run->month);
        $former = $this->formerEmployees($run->month, $inService->pluck('id'));
        $lineByEmployee = $lines->keyBy('employee_id');

        foreach ($inService as $employee) {
            if (! $lineByEmployee->has($employee->id)) {
                $problems[] = $employee->full_name.': مستحق في هذا الشهر وغير موجود في الكشف؛ أعد الكشف للمسودة وأعد الحساب.';
            }
        }

        // Former employees are not required on every run — but a new locked-month adjustment must not be skipped.
        foreach ($former as $employee) {
            if ($lineByEmployee->has($employee->id)) {
                continue;
            }
            $currency = $this->lastLockedLine($employee->id, $run->month)?->currency_code;
            if ($currency === null) {
                continue;
            }
            $expected = $this->computeAdjustments($employee, $run->month, $currency);
            if ($expected['items'] !== []) {
                $problems[] = $employee->full_name.': ظهرت تسوية مستحقة بعد تجهيز الكشف؛ أعد الكشف للمسودة وأعد الحساب.';
            }
        }

        foreach ($lines as $line) {
            $employee = Employee::query()->findOrFail($line->employee_id);
            $name = $employee->full_name;
            $served = $inService->contains('id', $employee->id);
            $fresh = $this->calculator->forEmployee($employee, $run->month);
            $expected = $this->computeAdjustments($employee, $run->month, $line->currency_code);
            $hasManual = $line->items()->where('origin', 'manual')->exists();

            if (! $served) {
                if (! $former->contains('id', $employee->id) && ! $hasManual) {
                    $problems[] = $name.': لم يعد مستحقاً لهذا الشهر؛ أعد الكشف للمسودة وأعد الحساب.';
                } elseif ($expected['items'] === [] && ! $hasManual) {
                    $problems[] = $name.': لا تسوية مستحقة بعد؛ أعد الكشف للمسودة وأعد الحساب.';
                }
            } else {
                if ($requireAttendanceReady && ! $fresh['attendance_ready']) {
                    $problems[] = $name.': حضور الشهر غير جاهز ('.$fresh['blocked_days'].' يوم).';
                }
                if ($this->fingerprint($fresh) !== $line->source_hash) {
                    $problems[] = $name.': تغيّرت بيانات الحضور أو العقد بعد الحساب؛ أعد الكشف للمسودة وأعد الحساب.';
                }
                foreach (collect($fresh['issues'])->where('blocking', true) as $issue) {
                    $problems[] = $name.': '.$issue['message'];
                }
            }

            if ($served && $line->currency_code !== null && $this->installmentsDrifted($line, $run->month)) {
                $problems[] = $name.': تغيّرت أقساط السلف المستحقة بعد الحساب؛ أعد الكشف للمسودة وأعد الحساب.';
            }
            if ($this->adjustmentsDrifted($line, $expected['items'])) {
                $problems[] = $name.': تغيّرت مصادر أو مبالغ تسويات الأشهر المقفلة؛ أعد الكشف للمسودة وأعد الحساب.';
            }
        }

        return $problems;
    }

    /** True when stored adjustment rows no longer match a fresh reprice of locked months. */
    private function adjustmentsDrifted(PayRunLine $line, array $expectedItems): bool
    {
        $stored = $line->items()->where('origin', 'adjustment')->get()
            ->mapWithKeys(fn (PayRunItem $item) => [$this->adjustmentKey($item->details) => $item]);
        $expected = collect($expectedItems)
            ->mapWithKeys(fn (array $item) => [$this->adjustmentKey($item['details']) => $item]);
        if ($stored->keys()->sort()->values()->all() !== $expected->keys()->sort()->values()->all()) {
            return true;
        }
        foreach ($expected as $key => $item) {
            $row = $stored->get($key);
            if ($row === null) {
                return true;
            }
            if ($row->category !== $item['category'] || abs((float) $row->proposed_amount - (float) $item['amount']) >= 0.01) {
                return true;
            }
            foreach (['locked', 'locked_proposed', 'recomputed', 'previously_settled', 'signed_delta'] as $field) {
                if (abs((float) ($row->details[$field] ?? 0) - (float) ($item['details'][$field] ?? 0)) >= 0.01) {
                    return true;
                }
            }
        }

        return false;
    }

    private function adjustmentKey(?array $details): string
    {
        return ($details['source_month'] ?? '').'|'.($details['source_kind'] ?? '').'|'.number_format((float) ($details['signed_delta'] ?? 0), 2, '.', '');
    }

    /**
     * Target signed amount for a locked kind after recompute.
     * Starts from the applied decision (amount), follows attendance/data changes relative to the original
     * proposal, and never re-imposes a waived/reduced deduction without a new finance decision—only
     * liability beyond the original proposal is newly proposed.
     */
    private function adjustmentTarget(float $applied, float $proposed, float $fresh): float
    {
        if (abs($fresh - $proposed) < 0.005) {
            return $applied;
        }

        $shifted = $applied + ($fresh - $proposed);
        // Finance softened a deduction (waive/modify): keep that relief unless the recomputed liability grows past the original proposal.
        if ($proposed < -0.005 && $applied > $proposed + 0.005) {
            if ($fresh < $proposed - 0.005) {
                return $shifted;
            }

            return max($applied, $fresh);
        }

        return $shifted;
    }

    /** Net of accepted adjustments already applied in approved runs for one locked month and kind. */
    private function settledAdjustments(int $employeeId, string $sourceMonth, string $kind, string $beforeMonth): float
    {
        return (float) PayRunItem::query()->where('origin', 'adjustment')->where('status', 'approved')
            ->whereHas('line', fn ($q) => $q->where('employee_id', $employeeId)
                ->whereHas('run', fn ($r) => $r->where('status', 'approved')->where('month', '<', $beforeMonth)))
            ->get()->filter(fn (PayRunItem $item) => ($item->details['source_month'] ?? null) === $sourceMonth && ($item->details['source_kind'] ?? null) === $kind)
            ->sum(fn (PayRunItem $item) => $this->signed($item->category, (float) $item->amount));
    }

    private function storeItem(PayRunLine $line, array $item, string $status): void
    {
        $line->items()->create([
            'kind' => $item['kind'], 'component_id' => $item['component_id'] ?? $this->componentId($item['kind']),
            'project_id' => $item['project_id'] ?? null, 'category' => $item['category'], 'origin' => $item['origin'], 'unit' => $item['unit'],
            'quantity' => $item['quantity'], 'proposed_amount' => $item['amount'],
            // Anything awaiting a finance decision counts as zero until it is approved or modified.
            'amount' => $status === 'proposed' ? 0 : $item['amount'],
            'status' => $status, 'details' => $item['details'] ?? null,
        ]);
    }

    private function totals(PayRunLine $line): void
    {
        // An item still awaiting a finance decision never counts, whatever amount an older version stored.
        $items = $line->items()->where('status', '!=', 'proposed')->get();
        $earnings = round($items->where('category', 'earning')->sum(fn ($i) => (float) $i->amount), 2);
        $deductions = round($items->where('category', 'deduction')->sum(fn ($i) => (float) $i->amount), 2);
        $line->update(['earnings' => $earnings, 'deductions' => $deductions, 'net' => round($earnings - $deductions, 2)]);
    }

    private function signed(?string $category, float $amount): float
    {
        return $category === 'deduction' ? -$amount : $amount;
    }

    /** Detects attendance, leave or contract changes between calculation and approval. */
    private function fingerprint(array $result): string
    {
        $payload = [$result['contract_id'], $result['currency_code'], $result['rate'], $result['issues'],
            collect($result['items'])->map(fn ($i) => [$i['kind'], $i['amount']])->all()];
        // Recurring components join the fingerprint only when present, so earlier fingerprints stay valid.
        if (($result['recurring'] ?? []) !== []) {
            $payload[] = collect($result['recurring'])->map(fn ($i) => [$i['kind'], $i['amount'], $i['project_id']])->all();
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** Employees with at least one service day in the month. */
    private function employeesFor(string $month): Collection
    {
        $from = $month.'-01';
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();

        return Employee::query()->whereIn('id', EmployeeServicePeriod::query()->whereDate('start_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))->select('employee_id'))
            ->orderBy('id')->get();
    }

    /** Employees not in service this month who were paid in a locked month inside the adjustment window. */
    private function formerEmployees(string $month, Collection $inService): Collection
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->subMonths(self::ADJUSTMENT_LOOKBACK_MONTHS)->format('Y-m');

        return Employee::query()->whereNotIn('id', $inService)
            ->whereIn('id', PayRunLine::query()->whereHas('run', fn ($q) => $q->where('status', 'approved')
                ->where('month', '<', $month)->where('month', '>=', $from))->select('employee_id'))
            ->orderBy('id')->get();
    }

    private function lastLockedLine(int $employeeId, string $month): ?PayRunLine
    {
        return PayRunLine::query()->where('employee_id', $employeeId)->whereNotNull('currency_code')
            ->whereHas('run', fn ($q) => $q->where('status', 'approved')->where('month', '<', $month))
            ->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.pay_run_id')->orderByDesc('pay_runs.month')
            ->select('pay_run_lines.*')->first();
    }

    /** One pending installment due by the month in the line's currency = one `advance_deduction` item (§5.9-2). */
    private function addInstallments(PayRunLine $line, string $month): void
    {
        if ($line->currency_code === null) {
            return;
        }
        $issues = $line->issues ?? [];
        foreach ($this->advances->installmentsDue($line->employee_id, $month) as $installment) {
            if ($installment['currency'] !== $line->currency_code) {
                $issues[] = ['code' => 'advance_currency', 'message' => 'قسط سلفة مستحق بعملة '.$installment['currency'].' لا يُخصم من راتب بعملة '
                    .$line->currency_code.'؛ يُسدَّد خارج الكشف أو يؤجَّل من شاشة السلف.', 'detail' => $installment['id'], 'blocking' => false];

                continue;
            }
            $line->items()->create(['kind' => 'advance_deduction', 'component_id' => $this->componentId('advance_deduction'), 'category' => 'deduction',
                'origin' => 'advance', 'unit' => null, 'quantity' => null, 'proposed_amount' => $installment['amount'], 'amount' => $installment['amount'],
                'status' => 'applied', 'details' => ['installment_id' => $installment['id'], 'advance_id' => $installment['advance_id'],
                    'due_month' => $installment['due_month']]]);
        }
        $line->update(['issues' => $issues]);
    }

    /** R18: a non-blocking warning while advance installments take the net below zero; finance may postpone one. */
    private function flagAdvancesOverNet(PayRunLine $line): void
    {
        $issues = array_values(array_filter($line->issues ?? [], fn (array $i) => $i['code'] !== 'advance_exceeds_net'));
        $installments = $line->items()->where('origin', 'advance')->get();
        if ($installments->isNotEmpty() && (float) $line->net < 0) {
            $issues[] = ['code' => 'advance_exceeds_net', 'message' => 'أقساط السلف تتجاوز صافي الراتب؛ يمكن للمالية تأجيل قسط.',
                'detail' => $installments->map(fn (PayRunItem $i) => $i->details['installment_id'])->values()->all(), 'blocking' => false];
        }
        $line->update(['issues' => $issues]);
    }

    /** True when the line's installment items no longer match the installments due now. */
    private function installmentsDrifted(PayRunLine $line, string $month): bool
    {
        $stored = $line->items()->where('origin', 'advance')->get()->map(fn (PayRunItem $i) => (int) ($i->details['installment_id'] ?? 0))
            ->sort()->values()->all();
        $due = $this->advances->installmentsDue($line->employee_id, $month)->where('currency', $line->currency_code)->pluck('id')
            ->sort()->values()->all();

        return $stored !== $due;
    }

    /** @return list<int> installment ids the run deducts */
    private function installmentIds(PayRun $run): array
    {
        return $this->items($run)->where('origin', 'advance')->get()->map(fn (PayRunItem $i) => (int) $i->details['installment_id'])->values()->all();
    }

    /**
     * Each line's applied items for the accrual (#15): the component's account, the project of a project-linked item,
     * the installment of an advance deduction.
     *
     * @param  Collection<int, PayRunLine>  $lines
     */
    private function accrualLines(PayRun $run, Collection $lines): array
    {
        $components = SalaryComponent::query()->get()->keyBy('id');
        $items = PayRunItem::query()->whereIn('pay_run_line_id', $lines->pluck('id'))->where('status', '!=', 'proposed')->orderBy('id')->get()
            ->groupBy('pay_run_line_id');

        return $lines->filter(fn (PayRunLine $l) => $l->currency_code !== null)
            ->map(fn (PayRunLine $l) => ['employee_id' => $l->employee_id, 'currency' => $l->currency_code, 'memo' => 'راتب '.$run->month,
                'items' => $items->get($l->id, collect())->filter(fn (PayRunItem $i) => (float) $i->amount > 0)
                    ->map(fn (PayRunItem $i) => ['category' => $i->category, 'amount' => (string) $i->amount,
                        'account_id' => $components->get($i->component_id)?->expense_account_id, 'project_id' => $i->project_id,
                        'installment_id' => $i->origin === 'advance' ? ($i->details['installment_id'] ?? null) : null,
                        'label' => $components->get($i->component_id)?->name])->values()->all()])
            ->values()->all();
    }

    /** The component HR chose for a manual item; without one, the general bonus or deduction. */
    private function manualComponent(array $data): SalaryComponent
    {
        $component = isset($data['component_id'])
            ? SalaryComponent::query()->find($data['component_id'])
            : SalaryComponent::query()->where('code', ($data['category'] ?? null) === 'earning' ? 'bonus' : 'manual_deduction')->first();
        if ($component === null || ! $component->is_active || ($component->is_system && ! in_array($component->code, SalaryComponent::MANUAL_SYSTEM, true))) {
            throw ValidationException::withMessages(['component_id' => ['اختر بنداً فعّالاً من بنود الراتب يُدخل يدوياً.']]);
        }
        if (isset($data['category']) && $data['category'] !== $component->kind) {
            throw ValidationException::withMessages(['category' => ['نوع البند (استحقاق أو استقطاع) لا يطابق بند الراتب المختار.']]);
        }

        return $component;
    }

    /**
     * A fixed component takes the amount entered. A percentage component: percentage (the item's or the component's) ×
     * base; the base is the amount entered as `base_amount`, or for `basic_salary` the line's basic pay this month.
     *
     * @return array{0: float, 1: ?array}
     */
    private function manualAmount(SalaryComponent $component, PayRunLine $line, array $data): array
    {
        if ($component->calc_method !== 'percentage') {
            if (! isset($data['amount'])) {
                throw ValidationException::withMessages(['amount' => ['أدخل المبلغ.']]);
            }

            return [round((float) $data['amount'], 2), null];
        }
        $percentage = (float) ($data['percentage'] ?? $component->percentage ?? 0);
        if ($percentage <= 0 || $percentage > 100) {
            throw ValidationException::withMessages(['percentage' => ['أدخل نسبة البند بين 0 و100.']]);
        }
        if (isset($data['base_amount'])) {
            $base = (float) $data['base_amount'];
        } elseif ($component->percentage_base === 'basic_salary') {
            $base = (float) $line->items()->where('status', '!=', 'proposed')->get()->sum(fn (PayRunItem $i) => match (true) {
                in_array($i->kind, PayrollCalculator::BASE_PAY_KINDS, true) => (float) $i->amount,
                in_array($i->kind, ['unpaid_leave', 'partial_pay_leave'], true) => -(float) $i->amount,
                default => 0.0,
            });
        } else {
            throw ValidationException::withMessages(['base_amount' => ['أدخل أتعاب المشروع التي تُحسب منها النسبة.']]);
        }
        $amount = round(max(0, $base) * $percentage / 100, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['مبلغ البند المحسوب صفر.']]);
        }

        return [$amount, ['percentage' => $percentage, 'base' => round($base, 2)]];
    }

    /** The system component whose code is the item kind (read each time: components may change between calls). */
    private function componentId(string $code): ?int
    {
        $id = SalaryComponent::query()->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function items(PayRun $run)
    {
        return PayRunItem::query()->whereIn('pay_run_line_id', PayRunLine::query()->where('pay_run_id', $run->id)->select('id'));
    }

    private function lock(PayRun $run, string $status): PayRun
    {
        $run = PayRun::query()->lockForUpdate()->findOrFail($run->id);
        abort_unless($run->status === $status, 409, match ($status) {
            'draft' => 'الكشف ليس مسودة.', 'submitted' => 'الكشف ليس بانتظار الاعتماد.', default => 'حالة الكشف لا تسمح بذلك.',
        });

        return $run;
    }

    private function touch(PayRun $run): void
    {
        $run->update(['version' => $run->version + 1]);
    }

    private function notify(string $permission, string $kind, string $message, PayRun $run): void
    {
        $users = app(Access::class)->usersWith($permission);
        if ($users->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($users, new HrNotification($kind, $message, ['pay_run_id' => $run->id, 'month' => $run->month])));
        }
    }
}
