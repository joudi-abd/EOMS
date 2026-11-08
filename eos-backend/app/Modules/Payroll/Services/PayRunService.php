<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Finance\Services\CashService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Notifications\HrNotification;
use App\Modules\HR\Services\EmploymentPeriodService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
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
 * Monthly pay run workflow: finance prepares a draft (payroll.manage), the company manager reviews proposed
 * deductions and approves (payroll.approve), which locks the month; finance then records one payment per line.
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
            PayRunLine::query()->where('pay_run_id', $run->id)->whereNotIn('employee_id', $employees->pluck('id'))
                ->whereDoesntHave('items', fn ($q) => $q->where('origin', 'manual'))->delete();
            foreach ($employees as $employee) {
                $result = $this->calculator->forEmployee($employee, $run->month);
                $line = PayRunLine::query()->firstOrNew(['pay_run_id' => $run->id, 'employee_id' => $employee->id]);
                $line->fill([
                    'contract_id' => $result['contract_id'], 'pay_type' => $result['pay_type'], 'currency_code' => $result['currency_code'],
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
                'origin' => 'manual', 'proposed_amount' => $data['amount'], 'amount' => $data['amount'], 'status' => 'applied',
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

    /** Finance accepts or rejects an adjustment for a locked month before submitting the draft. */
    public function decideAdjustment(PayRunItem $item, bool $accept, string $reason, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($item, $accept, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'draft');
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
            abort_if($this->items($run)->where('origin', 'adjustment')->where('status', 'proposed')->exists(), 409,
                'قرّر في تسويات الأشهر المقفلة قبل الإرسال.');
            $run->update(['status' => 'submitted', 'submitted_at' => now(), 'return_note' => null, 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_submitted', $run, null, null, $actor->id);
            $this->notify('payroll.approve', 'payroll.run_submitted', 'كشف رواتب '.$run->month.' بانتظار الاعتماد.', $run);

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

    /** The company manager accepts, changes (between 0 and the proposal) or waives a proposed deduction. */
    public function reviewProposed(PayRunItem $item, string $decision, ?float $amount, string $reason, User $actor): PayRunItem
    {
        return DB::transaction(function () use ($item, $decision, $amount, $reason, $actor) {
            $line = PayRunLine::query()->findOrFail($item->pay_run_line_id);
            $run = $this->lock($line->run, 'submitted');
            abort_unless($item->origin === 'proposed', 409, 'البند ليس خصماً مقترحاً.');
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
     * Approval locks the month. It needs every employee's attendance ready, no blocking line issue, no negative
     * net, and data unchanged since the last calculation. Proposed deductions not yet reviewed are accepted.
     */
    public function approve(PayRun $run, User $actor): PayRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = $this->lock($run, 'submitted');
            $problems = [];
            foreach (PayRunLine::query()->where('pay_run_id', $run->id)->orderBy('id')->get() as $line) {
                $employee = Employee::query()->findOrFail($line->employee_id);
                $fresh = $this->calculator->forEmployee($employee, $run->month);
                $name = $employee->full_name;
                if (! $fresh['attendance_ready']) {
                    $problems[] = $name.': حضور الشهر غير جاهز ('.$fresh['blocked_days'].' يوم).';
                } elseif ($this->fingerprint($fresh) !== $line->source_hash) {
                    $problems[] = $name.': تغيّرت بيانات الحضور أو العقد بعد الحساب؛ أعد الكشف للمسودة وأعد الحساب.';
                }
                foreach (collect($fresh['issues'])->where('blocking', true) as $issue) {
                    $problems[] = $name.': '.$issue['message'];
                }
            }
            if ($problems !== []) {
                throw ValidationException::withMessages(['run' => $problems]);
            }
            $this->items($run)->where('origin', 'proposed')->where('status', 'proposed')->get()->each(function (PayRunItem $item) use ($actor) {
                $item->update(['status' => 'approved', 'amount' => $item->proposed_amount, 'decided_by' => $actor->id, 'decided_at' => now()]);
            });
            foreach (PayRunLine::query()->where('pay_run_id', $run->id)->get() as $line) {
                $this->totals($line);
                if ((float) $line->net < 0) {
                    throw ValidationException::withMessages(['run' => [Employee::find($line->employee_id)?->full_name.': صافي الراتب سالب.']]);
                }
            }
            $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'version' => $run->version + 1]);
            $this->audit->log('payroll.run_approved', $run, null, ['month' => $run->month], $actor->id);
            $this->notify('payroll.manage', 'payroll.run_approved', 'اعتُمد كشف رواتب '.$run->month.' ويمكن البدء بالصرف.', $run);

            return $run->refresh();
        }, 3);
    }

    /** One payment per line: converted at the payment-date rate, then SYP rounded to 1000 and USD to 1. */
    public function recordPayment(PayRunLine $line, array $data, User $actor): PayPayment
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = PayRunLine::query()->lockForUpdate()->findOrFail($line->id);
            abort_unless($line->run->status === 'approved', 409, 'يُسجَّل الدفع بعد اعتماد الكشف.');
            abort_if($line->payment()->exists(), 409, 'سُجّل الدفع لهذا الموظف مسبقاً.');
            abort_unless((float) $line->net > 0, 409, 'لا يوجد مبلغ مستحق.');
            $paidCurrency = strtoupper($data['currency']);
            $rate = $this->rates->rateOn($line->currency_code, $paidCurrency, $data['paid_at']);
            if ($rate === null) {
                throw ValidationException::withMessages(['currency' => ['لا يوجد سعر صرف مسجّل بتاريخ الدفع؛ أدخله في الإعدادات أولاً.']]);
            }
            $converted = round((float) $line->net * $rate, 2);
            $step = $paidCurrency === 'SYP' ? 1000 : 1;
            $paid = round($converted / $step) * $step;
            $wallet = null;
            if ($data['method'] === 'sham_cash') {
                $wallet = Employee::query()->whereKey($line->employee_id)->value('sham_cash_wallet');
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
                'راتب '.$line->run->month.': '.Employee::find($line->employee_id)?->full_name, $payment->id, $actor);
            $payment->update(['finance_transaction_id' => $tx->id]);
            $this->audit->log('payroll.payment_recorded', $payment, null, $payment->getAttributes(), $actor->id);

            return $payment;
        });
    }

    /** Re-prices earlier locked months and proposes the difference not yet settled by earlier adjustments. */
    private function addAdjustments(PayRunLine $line, Employee $employee, string $month, Collection $decided): void
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->subMonths(self::ADJUSTMENT_LOOKBACK_MONTHS)->format('Y-m');
        $lockedLines = PayRunLine::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->where('status', 'approved')->where('month', '<', $month)->where('month', '>=', $from))
            ->with('run')->get();
        $issues = $line->issues;
        foreach ($lockedLines as $locked) {
            $fresh = $this->calculator->forEmployee($employee, $locked->run->month);
            if (! $fresh['attendance_ready']) {
                // Not blocking: the change waits until HR re-approves that month's days.
                $issues[] = ['code' => 'locked_month_pending', 'message' => 'تغييرات قيد المراجعة على شهر مقفل '.$locked->run->month.'؛ ستظهر تسويتها بعد اعتماد الحضور.', 'detail' => $locked->run->month, 'blocking' => false];

                continue;
            }
            if ($fresh['currency_code'] !== $line->currency_code) {
                $issues[] = ['code' => 'adjustment_currency', 'message' => 'عملة الشهر المقفل '.$locked->run->month.' تختلف عن عملة هذا الشهر؛ التسوية يدوية.', 'detail' => $locked->run->month, 'blocking' => false];

                continue;
            }
            $recomputed = collect($fresh['items'])->keyBy('kind');
            $original = $locked->items()->whereIn('origin', ['auto', 'proposed'])->get()->keyBy('kind');
            foreach ($recomputed->keys()->merge($original->keys())->unique() as $kind) {
                $new = $this->signed($recomputed->get($kind)['category'] ?? null, (float) ($recomputed->get($kind)['amount'] ?? 0));
                $old = $this->signed($original->get($kind)?->category, (float) ($original->get($kind)?->proposed_amount ?? 0));
                $settled = $this->settledAdjustments($employee->id, $locked->run->month, $kind, $month);
                $delta = round($new - $old - $settled, 2);
                if (abs($delta) >= 0.01) {
                    $details = ['source_month' => $locked->run->month, 'source_kind' => $kind, 'locked' => $old,
                        'recomputed' => $new, 'previously_settled' => $settled, 'signed_delta' => $delta];
                    $previous = $decided->get($this->adjustmentKey($details));
                    if ($previous) {
                        $line->items()->create([...$previous->only(['kind', 'category', 'origin', 'proposed_amount', 'amount',
                            'status', 'reason', 'decided_by', 'decided_at']), 'details' => $details]);

                        continue;
                    }
                    $this->storeItem($line, [
                        'kind' => 'adjustment', 'category' => $delta > 0 ? 'earning' : 'deduction', 'origin' => 'adjustment',
                        'unit' => null, 'quantity' => null, 'amount' => abs($delta), 'details' => $details,
                    ], 'proposed');
                }
            }
        }
        $line->update(['issues' => $issues]);
    }

    private function adjustmentKey(?array $details): string
    {
        return ($details['source_month'] ?? '').'|'.($details['source_kind'] ?? '').'|'.number_format((float) ($details['signed_delta'] ?? 0), 2, '.', '');
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
