<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Resources\AttendanceDayResource;
use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AttendanceAccess;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceCalculator $calculator, private AttendanceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01'],
            'employee_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'status' => ['nullable', Rule::in(['pending', 'present', 'incomplete', 'absent', 'absent_unconfirmed', 'day_off', 'outside_service'])],
            'review_status' => ['nullable', Rule::in(['draft', 'approved'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $employees = Employee::query()->when($data['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id));
        // Apply result filters AFTER all eligible employees have been calculated.
        (clone $employees)->orderBy('id')->chunkById(100, function ($items) use ($data) {
            foreach ($items as $employee) {
                $this->calculator->calculate($employee, $data['date']);
            }
        });
        $query = AttendanceDay::query()->where('work_date', $data['date'])
            ->whereIn('employee_id', (clone $employees)->select('id'))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        if (($data['review_status'] ?? null) === 'approved') {
            $query->whereNotNull('approved_at');
        } elseif (($data['review_status'] ?? null) === 'draft') {
            $query->whereNull('approved_at');
        }
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);
        $paginator = $query->orderBy('employee_id')->paginate($data['per_page'] ?? 20);
        $paginator->setCollection($paginator->getCollection()->map(fn ($day) => (new AttendanceDayResource($day))->resolve($request)));
        $response = ApiResponse::paginated($paginator);
        $body = $response->getData(true);
        $body['summary'] = ['total' => $counts->sum(), 'by_status' => $counts];

        return response()->json($body);
    }

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        $this->access->employee($request, $employee);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01']]);
        $day = $this->calculator->calculate($employee, $data['date']);

        return $this->detail($request, $day);
    }

    public function show(Request $request, AttendanceDay $day): JsonResponse
    {
        $employee = Employee::findOrFail($day->employee_id);
        $this->access->employee($request, $employee);

        return $this->detail($request, $this->calculator->calculate($employee, $day->work_date->format('Y-m-d')));
    }

    private function detail(Request $request, AttendanceDay $day): JsonResponse
    {
        $policy = AttendancePolicy::findOrFail($day->policy_id);
        $start = CarbonImmutable::parse($day->work_date->format('Y-m-d'), $policy->timezone)->startOfDay()->utc();
        $end = $start->setTimezone($policy->timezone)->addDay()->utc();

        return ApiResponse::ok([
            'day' => (new AttendanceDayResource($day))->resolve($request),
            'punches' => AttendancePunch::query()->where('employee_id', $day->employee_id)
                ->where('occurred_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $end->format('Y-m-d H:i:s'))->orderBy('occurred_at')->orderBy('id')->get(),
            'corrections' => AttendanceCorrection::query()->where('day_id', $day->id)->orderByDesc('id')->get(),
        ]);
    }

    public function unmatched(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => ['nullable', 'integer', 'exists:hr_attendance_devices,id'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return ApiResponse::paginated(AttendancePunch::query()->whereNull('employee_id')
            ->when($data['device_id'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 50));
    }

    public function report(Request $request, Employee $employee): JsonResponse
    {
        $this->access->employee($request, $employee);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'], 'UTC');
        $to = CarbonImmutable::parse($data['to'], 'UTC');
        abort_if($from->diffInDays($to) > 30, 422, 'الحد الأقصى 31 يوماً.');
        $days = collect();
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $days->push($this->calculator->calculate($employee, $date->toDateString()));
        }

        return ApiResponse::ok([
            'days' => $days->map(fn ($d) => (new AttendanceDayResource($d))->resolve($request)),
            'summary' => [
                'days' => $days->count(), 'by_status' => $days->countBy('status'),
                'worked_minutes' => $days->sum('worked_minutes'), 'late_minutes' => $days->sum('late_minutes'),
                'early_minutes' => $days->sum('early_minutes'), 'approved_days' => $days->whereNotNull('approved_at')->count(),
                'unknown_worked_days' => $days->whereNull('worked_minutes')->count(),
                'pending_change_days' => $days->where('pending_changes', true)->count(),
                'financial_effect_applied' => false,
            ],
        ]);
    }

    public function requestCorrection(Request $request, AttendanceDay $day, AttendanceReviewService $reviews): JsonResponse
    {
        $employee = Employee::findOrFail($day->employee_id);
        abort_unless($request->user()->can('attendance.adjust') || (int) $employee->user_id === (int) $request->user()->id, 403);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['times', 'absence'])],
            'first_in' => ['required_if:type,times', 'prohibited_if:type,absence', 'string', 'max:40'],
            'last_out' => ['required_if:type,times', 'prohibited_if:type,absence', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return ApiResponse::created($reviews->request($day, $data, $request->user()));
    }

    public function reviewCorrection(Request $request, AttendanceCorrection $correction, AttendanceReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return ApiResponse::ok($reviews->review($correction, $data, $request->user()));
    }

    public function approve(Request $request, AttendanceDay $day, AttendanceReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::ok(new AttendanceDayResource($reviews->approve($day, $data['version'], $request->user())));
    }

    public function reopen(Request $request, AttendanceDay $day, AttendanceReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return ApiResponse::ok(new AttendanceDayResource($reviews->reopen($day, $data['version'], $data['reason'], $request->user())));
    }
}
