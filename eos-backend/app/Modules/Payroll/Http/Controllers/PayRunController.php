<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Payroll\Services\PayRunService;
use App\Support\ApiResponse;
use App\Support\CsvResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayRunController extends Controller
{
    public function __construct(private PayRunService $runs) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved'])]]);
        $page = PayRun::query()->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('month')->paginate(24);
        $page->setCollection($page->getCollection()->map(fn (PayRun $run) => $this->runSummary($run)));

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:2000-01']]);

        return ApiResponse::created($this->detail($this->runs->create($data['month'], $request->user())));
    }

    public function show(PayRun $run): JsonResponse
    {
        return ApiResponse::ok($this->detail($run));
    }

    public function line(PayRun $run, PayRunLine $line): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->id, 404);

        return ApiResponse::ok($this->lineDetail($line));
    }

    public function recalculate(PayRun $run, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->detail($this->runs->recalculate($run, $request->user())));
    }

    public function addItem(PayRun $run, Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'category' => ['required', Rule::in(['earning', 'deduction'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return ApiResponse::created($this->runs->addManualItem($run, $data, $request->user()));
    }

    public function removeItem(PayRunItem $item, Request $request): JsonResponse
    {
        $this->runs->removeManualItem($item, $request->user());

        return ApiResponse::message('تم حذف البند.');
    }

    public function decideAdjustment(PayRunItem $item, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return ApiResponse::ok($this->runs->decideAdjustment($item, $data['decision'] === 'accept', $data['reason'], $request->user()));
    }

    public function reviewDeduction(PayRunItem $item, Request $request): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'modify', 'waive'])],
            'amount' => ['required_if:decision,modify', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'reason' => [Rule::requiredIf($request->input('decision') !== 'approve'), 'nullable', 'string', 'min:3', 'max:1000'],
        ]);

        return ApiResponse::ok($this->runs->reviewProposed($item, $data['decision'], isset($data['amount']) ? (float) $data['amount'] : null,
            $data['reason'] ?? 'اعتماد الخصم المقترح', $request->user()));
    }

    public function submit(PayRun $run, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->runSummary($this->runs->submit($run, $request->user())));
    }

    public function returnToDraft(PayRun $run, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        return ApiResponse::ok($this->runSummary($this->runs->returnToDraft($run, $data['note'], $request->user())));
    }

    public function approve(PayRun $run, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->runSummary($this->runs->approve($run, $request->user())));
    }

    public function pay(PayRunLine $line, Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(['SYP', 'USD'])], 'method' => ['required', Rule::in(['cash', 'sham_cash'])],
            'box_id' => ['required', 'integer', 'exists:fin_boxes,id'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['required_if:method,sham_cash', 'nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::created($this->runs->recordPayment($line, $data, $request->user()));
    }

    public function voidPayment(PayPayment $payment, Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->runs->voidPayment($payment, $data['date'], $data['reason'], $request->user()));
    }

    /** Finance-only payslip sheet: one row per employee with totals and payment. */
    public function export(PayRun $run): StreamedResponse
    {
        $lines = PayRunLine::query()->where('pay_run_id', $run->id)->with(['items', 'payment'])->orderBy('employee_id')->get();
        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
        $sum = fn ($line, $kind) => $line->items->where('kind', $kind)->sum(fn ($i) => (float) $i->amount);

        return CsvResponse::download('payroll-'.$run->month.'.csv', [
            'month', 'employee_no', 'employee_name', 'pay_type', 'currency', 'rate', 'salary_or_base', 'overtime', 'bonus',
            'unpaid_leave', 'partial_pay_leave', 'late', 'early_exit', 'absence', 'manual_deduction', 'adjustments',
            'earnings', 'deductions', 'net', 'paid_currency', 'exchange_rate', 'paid_amount', 'method', 'reference', 'paid_at',
        ], $lines->map(function (PayRunLine $line) use ($run, $employees, $sum) {
            $employee = $employees->get($line->employee_id);
            $adjustments = $line->items->where('origin', 'adjustment')->sum(fn ($i) => ($i->category === 'deduction' ? -1 : 1) * (float) $i->amount);

            return [$run->month, $employee?->employee_no, $employee?->full_name, $line->pay_type, $line->currency_code, $line->rate,
                $sum($line, 'salary') + $sum($line, 'days_worked') + $sum($line, 'hours_worked') + $sum($line, 'paid_leave') + $sum($line, 'holiday_pay'),
                $sum($line, 'overtime'), $sum($line, 'bonus'), $sum($line, 'unpaid_leave'), $sum($line, 'partial_pay_leave'),
                $sum($line, 'late'), $sum($line, 'early_exit'), $sum($line, 'absence'), $sum($line, 'manual_deduction'), round($adjustments, 2),
                $line->earnings, $line->deductions, $line->net, $line->payment?->paid_currency, $line->payment?->exchange_rate,
                $line->payment?->paid_amount, $line->payment?->method, $line->payment?->reference, $line->payment?->paid_at?->format('Y-m-d')];
        }));
    }

    private function runSummary(PayRun $run): array
    {
        $lines = PayRunLine::query()->where('pay_run_id', $run->id)->withCount('payment')->get();

        return [...$run->only(['id', 'month', 'status', 'prepared_by', 'approved_by', 'return_note', 'version']),
            'calculated_at' => $run->calculated_at?->toIso8601String(), 'submitted_at' => $run->submitted_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(),
            'employees' => $lines->count(),
            'not_ready' => $lines->where('attendance_ready', false)->count(),
            'blocking_issues' => $lines->filter(fn ($l) => collect($l->issues)->contains('blocking', true))->count(),
            'paid' => $lines->where('payment_count', '>', 0)->count(),
            'payable' => $lines->filter(fn ($l) => (float) $l->net > 0)->count(),
            'totals' => $lines->groupBy('currency_code')->map(fn ($group) => [
                'earnings' => round($group->sum(fn ($l) => (float) $l->earnings), 2),
                'deductions' => round($group->sum(fn ($l) => (float) $l->deductions), 2),
                'net' => round($group->sum(fn ($l) => (float) $l->net), 2)]),
        ];
    }

    private function detail(PayRun $run): array
    {
        $lines = PayRunLine::query()->where('pay_run_id', $run->id)->with(['payment'])->orderBy('employee_id')->get();
        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');

        return [...$this->runSummary($run), 'lines' => $lines->map(fn (PayRunLine $line) => [
            ...$line->only(['id', 'employee_id', 'contract_id', 'pay_type', 'currency_code', 'rate', 'working_days',
                'attendance_ready', 'blocked_days', 'issues', 'earnings', 'deductions', 'net']),
            'employee_name' => $employees->get($line->employee_id)?->full_name,
            'employee_no' => $employees->get($line->employee_id)?->employee_no,
            'paid' => $line->payment !== null,
        ])->values()];
    }

    private function lineDetail(PayRunLine $line): array
    {
        $employee = Employee::find($line->employee_id);

        return [...$line->only(['id', 'pay_run_id', 'employee_id', 'contract_id', 'pay_type', 'currency_code', 'rate',
            'working_days', 'attendance_ready', 'blocked_days', 'issues', 'earnings', 'deductions', 'net']),
            'employee_name' => $employee?->full_name, 'employee_no' => $employee?->employee_no,
            'sham_cash_wallet' => $employee?->sham_cash_wallet,
            'items' => $line->items()->orderBy('id')->get(), 'payment' => $line->payment];
    }
}
