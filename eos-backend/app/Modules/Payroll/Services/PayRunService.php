<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\CashService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Notifications\HrNotification;
use App\Modules\HR\Services\EmploymentPeriodService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
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
    /** How far back locked months are re-checked for adjustments. */
    private const ADJUSTMENT_LOOKBACK_MONTHS = 12;

    public function __construct(private PayrollCalculator $calculator, private AuditLogger $audit, private ExchangeRateService $rates, private CashService $cash) {}

    public function create(string $month, User $actor): PayRun
    {
        $today = app(EmploymentPeriodService::class)->today();
        if ($month >= substr($today, 0, 7)) {
            throw ValidationException::withMessages(['month' => ['يُنشأ كشف الشهر بعد انتهائه فقط.']]);
        }
        $run = DB::transaction(function () use ($month, $actor) {
            if (PayRun::query()->where('month', $month)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['month' => ['يوجد كشف لهذا الشهر مسبقاً.']]);
            }
            $run = PayRun::create(['month' => $month, 'prepared_by' => $actor->id]);
            $this->audit->log('payroll.run_created', $run, null, ['month' => $month], $actor->id);

            return $run;
        });

        return $this->recalculate($run, $actor);
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
                $line->items()->whereIn('origin', ['auto', 'proposed', 'adjustment'])->delete();
                foreach ($result['items'] as $item) {
                    $this->storeItem($line, $item, $item['origin'] === 'proposed' ? 'proposed' : 'applied');
                }
                $this->addAdjustments($line, $employee, $run->month, $decided);
                if ($former->contains('id', $employee->id) && ! $line->items()->exists()) {
                    $line->delete();

                    continue;
                }
                $this->totals($line);
            }
            $run->update(['calculated_at' => now(), 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_calculated', $run, null, ['lines' => $employees->count()], $actor->id);

            return $run->refresh();
        }, 3);
    }

    public function addManualItem(PayRun $run, array $data, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($run, $data, $actor) {
            $run = $this->lock($run, 'draft');
            $line = PayRunLine::query()->where('pay_run_id', $run->id)->where('employee_id', $data['employee_id'])->firstOrFail();
            $item = $line->items()->create([
                'kind' => $data['category'] === 'earning' ? 'bonus' : 'manual_deduction', 'category' => $data['category'],
                // HR proposes; finance approves, changes or waives it when reviewing the run.
                'origin' => 'manual', 'proposed_amount' => $data['amount'], 'amount' => $data['amount'], 'status' => 'proposed',
                'reason' => $data['reason'], 'created_by' => $actor->id,
            ]);
            $this->totals($line);
            $this->touch($run);
            $this->audit->log('payroll.manual_item_added', $item, null, $item->getAttributes(), $actor->id);

            return $item;
        });
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
        });
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
        });
    }

    public function submit(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'draft');
            abort_if($run->calculated_at === null, 409, 'احسب الكشف أولاً.');
            // Same source recheck as approve: refuse a stale draft before finance starts reviewing it.
            $this->lockApprovalSources($run);
            $lines = PayRunLine::query()->where('pay_run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
            $problems = $this->approvalSourceProblems($run, $lines);
            if ($problems !== []) {
                throw ValidationException::withMessages(['run' => $problems]);
            }
            $run->update(['status' => 'submitted', 'submitted_at' => now(), 'return_note' => null, 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_submitted', $run, null, null, $actor->id);
            $this->notify('payroll.approve', 'payroll.run_submitted', 'كشف رواتب '.$run->month.' مرفوع من الموارد البشرية بانتظار اعتماد المالية.', $run);

            return $run->refresh();
        });
    }

    public function returnToDraft(PayRun $run, string $note, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $note, $actor) {
            $run = $this->lock($run, 'submitted');
            $run->update(['status' => 'draft', 'return_note' => $note, 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_returned', $run, null, ['note' => $note], $actor->id);
            $this->notify('payroll.manage', 'payroll.run_returned', 'أُعيد كشف رواتب '.$run->month.' للتعديل: '.$note, $run);

            return $run->refresh();
        });
    }

    /** Finance accepts, changes (between 0 and the proposal) or waives a system-proposed deduction or an item HR entered. */
    public function reviewProposed(PayRunItem $item, string $decision, ?float $amount, string $reason, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($item, $decision, $amount, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'submitted');
            abort_unless(in_array($item->origin, ['proposed', 'manual'], true), 409, 'البند ليس مقترحاً للمراجعة.');
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
        });
    }

    /**
     * Approval locks the month. It re-resolves the eligible roster, rechecks each line's attendance/contract
     * fingerprint, and re-prices locked-month adjustments against current sources. Any drift rejects approval
     * so HR must return the run to draft and recalculate. Proposed deductions not yet reviewed are accepted.
     */
    public function approve(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'submitted');
            abort_if($run->prepared_by === $actor->id, 403, 'لا يعتمد أحد كشفاً أنشأه بنفسه.');
            abort_if($this->items($run)->where('origin', 'adjustment')->where('status', 'proposed')->exists(), 409,
                'قرّر في تسويات الأشهر المقفلة قبل الاعتماد.');

            $this->lockApprovalSources($run);
            $lines = PayRunLine::query()->where('pay_run_id', $run->id)->orderBy('id')->lockForUpdate()->get();
            $problems = $this->approvalSourceProblems($run, $lines);
            if ($problems !== []) {
                throw ValidationException::withMessages(['run' => $problems]);
            }

            $this->items($run)->whereIn('origin', ['proposed', 'manual'])->where('status', 'proposed')->get()->each(function (PayRunItem $item) use ($actor) {
                $item->update(['status' => 'approved', 'amount' => $item->proposed_amount, 'decided_by' => $actor->id, 'decided_at' => now()]);
            });
            foreach ($lines as $line) {
                $this->totals($line->refresh());
                if ((float) $line->net < 0) {
                    throw ValidationException::withMessages(['run' => [Employee::find($line->employee_id)?->full_name.': صافي الراتب سالب.']]);
                }
            }
            $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_approved', $run, null, ['month' => $run->month], $actor->id);
            $this->notify('payroll.manage', 'payroll.run_approved', 'اعتمدت المالية كشف رواتب '.$run->month.'.', $run);
            $this->notify('payroll.pay', 'payroll.run_approved', 'اعتُمد كشف رواتب '.$run->month.' ويمكن البدء بالصرف.', $run);

            return $run->refresh();
        }, 3);
    }

    /**
     * One payment per line: converted at the payment-date rate, then SYP rounded to 1000 and USD to 1.
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
                if (! $wallet) {
                    throw ValidationException::withMessages(['method' => ['لا توجد محفظة شام كاش مسجّلة للموظف.']]);
                }
            }
            $payment = $line->payment()->create([
                'contract_currency' => $line->currency_code, 'net_amount' => $line->net, 'paid_currency' => $paidCurrency,
                'exchange_rate' => $rate, 'rate_date' => $data['paid_at'], 'converted_amount' => $converted,
                'paid_amount' => $paid, 'rounding_difference' => round($paid - $converted, 2), 'method' => $data['method'],
                'wallet' => $wallet, 'reference' => $data['reference'] ?? null, 'paid_at' => $data['paid_at'],
                'paid_by' => $actor->id, 'note' => $data['note'] ?? null,
            ]);
            // The salary leaves the chosen finance box: one posted cash operation and journal entry.
            $tx = $this->cash->payroll((int) $data['box_id'], $paidCurrency, $paid, $data['paid_at'],
                'راتب '.$run->month.': '.Employee::find($line->employee_id)?->full_name, $payment->id, $actor);
            $payment->update(['finance_transaction_id' => $tx->id]);
            $this->audit->log('payroll.payment_recorded', $payment, null, $payment->getAttributes(), $actor->id);

            return $payment;
        });
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
     * What a line pays in a currency on a date: converted at that day's rate, SYP rounded to 1000 and USD to 1.
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
        $step = $currency === 'SYP' ? 1000 : 1;

        return ['rate' => (float) $rate, 'converted' => $converted, 'paid' => (float) (round($converted / $step) * $step)];
    }

    /** Voids a wrong payment: the salary returns to its finance box by reversal and the line can be paid again. */
    public function voidPayment(PayPayment $payment, string $date, string $reason, User $actor): PayPayment
    {
        return DB::transaction(function () use ($payment, $date, $reason, $actor) {
            $payment = PayPayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_if($payment->voided_at !== null, 409, 'الدفعة ملغاة مسبقاً.');
            if ($payment->finance_transaction_id) {
                $this->cash->reverse(Transaction::query()->findOrFail($payment->finance_transaction_id),
                    $date, 'إلغاء دفعة راتب: '.$reason, $actor, fromPayroll: true);
            }
            $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->audit->log('payroll.payment_voided', $payment, null, ['reason' => $reason], $actor->id);

            return $payment->refresh();
        });
    }

    /** Re-prices earlier locked months and proposes the difference not yet settled by earlier adjustments. */
    private function addAdjustments(PayRunLine $line, Employee $employee, string $month, Collection $decided): void
    {
        ['issues' => $extraIssues, 'items' => $items] = $this->computeAdjustments($employee, $month, $line->currency_code);
        $issues = [...($line->issues ?? []), ...$extraIssues];
        foreach ($items as $item) {
            $previous = $decided->get($this->adjustmentKey($item['details']));
            if ($previous) {
                $line->items()->create([...$previous->only(['kind', 'category', 'origin', 'proposed_amount', 'amount',
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
     * Roster, current-month fingerprints, and locked-month adjustment payloads must still match the submitted run.
     *
     * @param  Collection<int, PayRunLine>  $lines
     * @return list<string>
     */
    private function approvalSourceProblems(PayRun $run, Collection $lines): array
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
                if (! $fresh['attendance_ready']) {
                    $problems[] = $name.': حضور الشهر غير جاهز ('.$fresh['blocked_days'].' يوم).';
                } elseif ($this->fingerprint($fresh) !== $line->source_hash) {
                    $problems[] = $name.': تغيّرت بيانات الحضور أو العقد بعد الحساب؛ أعد الكشف للمسودة وأعد الحساب.';
                }
                foreach (collect($fresh['issues'])->where('blocking', true) as $issue) {
                    $problems[] = $name.': '.$issue['message'];
                }
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
            'kind' => $item['kind'], 'category' => $item['category'], 'origin' => $item['origin'], 'unit' => $item['unit'],
            'quantity' => $item['quantity'], 'proposed_amount' => $item['amount'],
            // A proposal counts in full until the company manager decides; adjustments count once finance accepts.
            'amount' => $item['origin'] === 'adjustment' ? 0 : $item['amount'],
            'status' => $status, 'details' => $item['details'] ?? null,
        ]);
    }

    private function totals(PayRunLine $line): void
    {
        $items = $line->items()->get();
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
        return hash('sha256', json_encode([$result['contract_id'], $result['currency_code'], $result['rate'], $result['issues'],
            collect($result['items'])->map(fn ($i) => [$i['kind'], $i['amount']])->all()], JSON_THROW_ON_ERROR));
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
        $users = User::permission($permission)->where('status', 'active')->get();
        if ($users->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($users, new HrNotification($kind, $message, ['pay_run_id' => $run->id, 'month' => $run->month])));
        }
    }
}
