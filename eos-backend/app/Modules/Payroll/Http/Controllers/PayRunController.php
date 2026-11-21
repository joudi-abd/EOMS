<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\PayrollInputService;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\PayrollCalculator;
use App\Modules\Payroll\Services\PayRunService;
use App\Modules\Payroll\Services\ShamCashFile;
use App\Modules\Payroll\Services\ShamCashService;
use App\Modules\Settings\Services\CurrencyService;
use App\Support\ApiResponse;
use App\Support\CsvResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayRunController extends Controller
{
    public function __construct(private PayRunService $runs, private ShamCashService $shamCash, private CurrencyService $currencies) {}

    // ── Sham Cash bulk transfer ───────────────────────────────────────────────

    /** Creates the transfer file (new random DocId) and downloads it; the DocId is also in the X-Doc-Id header. */
    public function shamCash(PayRun $run, Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in($this->currencies->payrollCodes())],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'line_ids' => ['nullable', 'array', 'min:1', 'max:'.ShamCashFile::MAX_ROWS], 'line_ids.*' => ['integer', 'distinct'],
            'skip_missing' => ['nullable', 'boolean'],
        ]);
        $export = $this->shamCash->export($run, $data['currency'], $data['date'] ?? now()->toDateString(),
            isset($data['line_ids']) ? array_map('intval', $data['line_ids']) : null, (bool) ($data['skip_missing'] ?? false), $request->user());

        return $this->download($export);
    }

    public function shamCashExports(PayRun $run): JsonResponse
    {
        return ApiResponse::ok(PayExport::query()->where('pay_run_id', $run->id)->orderByDesc('id')->get()
            ->map(fn (PayExport $e) => [...$e->toArray(), 'status' => $e->confirmed_at ? 'confirmed' : ($e->cancelled_at ? 'cancelled' : 'open')]));
    }

    public function shamCashFile(PayExport $export): BinaryFileResponse
    {
        // The file carries wallets and amounts: every download is on the record.
        app(AuditLogger::class)->log('payroll.shamcash_downloaded', $export, null, ['doc_id' => $export->doc_id]);

        return $this->download($export);
    }

    public function confirmShamCash(PayExport $export, Request $request): JsonResponse
    {
        $data = $request->validate(['box_id' => ['required', 'integer', 'exists:fin_cash_boxes,id']]);

        return ApiResponse::ok($this->shamCash->confirm($export, (int) $data['box_id'], $request->user()));
    }

    public function cancelShamCash(PayExport $export, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->shamCash->cancel($export, $request->user()));
    }

    private function download(PayExport $export): BinaryFileResponse
    {
        return response()->download($this->shamCash->file($export), 'shamcash_'.$export->doc_id.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Doc-Id' => $export->doc_id,
                'Access-Control-Expose-Headers' => 'X-Doc-Id, Content-Disposition'])->deleteFileAfterSend();
    }

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

    /**
     * Days that still block payroll for this run: the same blockers as payroll-inputs, flattened for HR
     * so they can open or approve each day without auditing the month employee by employee.
     */
    public function attendanceBlockers(PayRun $run, PayrollInputService $inputs): JsonResponse
    {
        $lines = PayRunLine::query()->where('pay_run_id', $run->id)->where('attendance_ready', false)
            ->orderBy('employee_id')->get();
        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
        $days = [];
        foreach ($lines as $line) {
            $employee = $employees->get($line->employee_id);
            if ($employee === null) {
                continue;
            }
            foreach ($inputs->month($employee, $run->month)['days'] as $day) {
                if ($day['blockers'] === []) {
                    continue;
                }
                $days[] = [
                    'employee_id' => $line->employee_id,
                    'employee_name' => $employee->full_name,
                    'employee_no' => $employee->employee_no,
                    'line_id' => $line->id,
                    'day_id' => $day['id'],
                    'version' => $day['version'],
                    'date' => $day['date'],
                    'status' => $day['status'],
                    'blockers' => $day['blockers'],
                    'flags' => $day['flags'],
                ];
            }
        }

        return ApiResponse::ok(['month' => $run->month, 'count' => count($days), 'days' => $days]);
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
        // component_id: any active non-system component (or the general bonus / deduction); without it, `category`
        // picks the general bonus or deduction. A project-linked component needs project_id (R21).
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'category' => ['required_without:component_id', 'nullable', Rule::in(['earning', 'deduction'])],
            'component_id' => ['nullable', 'integer'], 'project_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999'],
            'base_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999999999'],
            'percentage' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
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
            'decision' => ['required', Rule::in(['approve', 'modify', 'waive', 'postpone'])],
            'amount' => ['required_if:decision,modify', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'reason' => [Rule::requiredIf($request->input('decision') !== 'approve'), 'nullable', 'string', 'min:3', 'max:1000'],
        ]);
        // R18: an advance installment is postponed (moved after the advance's last one) rather than reviewed.
        if ($data['decision'] === 'postpone') {
            return ApiResponse::ok($this->lineDetail($this->runs->postponeInstallment($item, $data['reason'], $request->user())));
        }

        return ApiResponse::ok($this->runs->reviewProposed($item, $data['decision'], isset($data['amount']) ? (float) $data['amount'] : null,
            $data['reason'] ?? 'اعتماد الخصم المقترح', $request->user()));
    }

    /** Approve or waive every item still awaiting a decision, optionally only some kinds (late, absence, bonus...). */
    public function reviewRemaining(PayRun $run, Request $request): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'waive'])],
            'kinds' => ['nullable', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in([...PayrollCalculator::PROPOSED_KINDS, ...SalaryComponent::query()->where('is_system', false)->pluck('code')->all(),
                ...SalaryComponent::MANUAL_SYSTEM])],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct'],
            'reason' => [Rule::requiredIf($request->input('decision') === 'waive'), 'nullable', 'string', 'min:3', 'max:1000'],
        ]);
        $count = $this->runs->reviewRemaining($run, $data['decision'], $data['kinds'] ?? null,
            $data['reason'] ?? 'اعتماد البنود المقترحة', $request->user(), $data['employee_ids'] ?? null);

        return ApiResponse::ok(['decided' => $count, 'run' => $this->runSummary($run->refresh())]);
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
            'currency' => ['required', Rule::in($this->currencies->payrollCodes())], 'method' => ['required', Rule::in(['cash', 'sham_cash'])],
            'box_id' => ['required', 'integer', 'exists:fin_cash_boxes,id'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['required_if:method,sham_cash', 'nullable', 'string', 'max:100'],
            'expected_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::created($this->runs->recordPayment($line, $data, $request->user()));
    }

    public function voidPayment(PayPayment $payment, Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->runs->voidPayment($payment, $data['date'], $data['reason'], $request->user()));
    }

    /**
     * Finance-only payslip sheet: one row per employee with a column per salary component used in the run (earnings
     * first, then deductions, in the components' order; a locked-month adjustment column is signed), then totals and payment.
     */
    public function export(PayRun $run): StreamedResponse
    {
        app(AuditLogger::class)->log('payroll.run_exported', $run, null, ['month' => $run->month]);
        $lines = PayRunLine::query()->where('pay_run_id', $run->id)->with(['items', 'payment'])->orderBy('employee_id')->get();
        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
        $components = SalaryComponent::query()->get()->keyBy('id');
        $byCode = $components->keyBy('code');
        $key = fn (PayRunItem $i) => $i->component_id ?? $byCode->get($i->kind)?->id ?? $i->kind;
        $columns = $lines->flatMap(fn (PayRunLine $l) => $l->items->map($key))->unique()
            ->sortBy(fn ($id) => [$components->get($id)?->kind === 'deduction' ? 1 : 0, $components->get($id)?->sort ?? PHP_INT_MAX, (string) $id])->values();
        $amount = fn (PayRunItem $i) => ($i->origin === 'adjustment' && $i->category === 'deduction' ? -1 : 1) * (float) $i->amount;

        return CsvResponse::download('payroll-'.$run->month.'.csv', [
            'month', 'employee_no', 'employee_name', 'pay_type', 'currency', 'rate',
            ...$columns->map(fn ($id) => $components->get($id)?->name ?? (string) $id)->all(),
            'earnings', 'deductions', 'net', 'paid_currency', 'exchange_rate', 'paid_amount', 'method', 'reference', 'paid_at',
        ], $lines->map(function (PayRunLine $line) use ($run, $employees, $columns, $key, $amount) {
            $employee = $employees->get($line->employee_id);
            $sums = $line->items->groupBy($key)->map(fn ($items) => round($items->sum($amount), 2));

            return [$run->month, $employee?->employee_no, $employee?->full_name, $line->pay_type, $line->currency_code, $line->rate,
                ...$columns->map(fn ($id) => $sums->get($id, 0))->all(),
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
            // Items still waiting for a finance decision (counted as zero); approval needs this at 0.
            'undecided_items' => PayRunItem::query()->whereIn('pay_run_line_id', $lines->pluck('id'))->where('status', 'proposed')->count(),
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
            'items' => $line->items()->with('component:id,code,name,kind,is_project_linked')->orderBy('id')->get(), 'payment' => $line->payment];
    }
}
