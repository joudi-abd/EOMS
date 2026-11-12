<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\SubmitLeaveRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\HrScope;
use App\Modules\HR\Services\LeaveWorkflowService;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Support\CsvResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveWorkflowService $service, private HrScope $scope) {}

    public function options(Request $request)
    {
        $data = $request->validate(['employee_id' => ['sometimes', 'integer', 'exists:hr_employees,id']]);

        return response()->json(['data' => $this->service->options($request->user(), $data['employee_id'] ?? null)]);
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        $page = $this->filtered($request, $actor)->orderByDesc('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row, $actor)));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /**
     * scope=mine (default) own requests; review = the approval queue and history of the actor's reviewer role;
     * all = every request the actor may see (organisation-wide with leaves.view, managed departments with
     * leaves.view_department). Filters apply to every scope. Shared with the CSV export.
     */
    public function filtered(Request $request, User $actor): Builder
    {
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['mine', 'review', 'all'])], 'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'withdrawn', 'cancelled', 'expired'])],
            'employee_id' => ['sometimes', 'integer'], 'leave_type_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'change_pending' => ['sometimes', 'boolean'],
        ]);
        $query = LeaveRequest::query();
        $scope = $data['scope'] ?? 'mine';
        if ($scope === 'review') {
            $staff = $actor->can('leaves.approve');
            $reviewers = $actor->can('leaves.approve_reviewers');
            abort_unless($staff || $reviewers, 403);
            if ($staff !== $reviewers) {
                // Requesters who approve leave themselves move up a level (see LeaveWorkflowService::levelOf).
                $approvers = app(Access::class)->usersWith('leaves.approve')->merge(app(Access::class)->usersWith('leaves.approve_reviewers'))->pluck('id')->all();
                $staff
                    ? $query->where('approval_level', LeaveWorkflowService::LEVEL_STAFF)->whereNotIn('submitted_by', $approvers)
                    : $query->where(fn ($q) => $q->where('approval_level', LeaveWorkflowService::LEVEL_REVIEWERS)->orWhereIn('submitted_by', $approvers));
            }
            $query->where('submitted_by', '!=', $actor->id);
        } elseif ($scope === 'all') {
            abort_unless($this->scope->hasAnyScope($actor, 'leaves'), 403);
            $visible = $this->scope->employeeIds($actor, 'leaves');
            $query->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible->all()));
        } else {
            $query->where('submitted_by', $actor->id);
        }

        return $query
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($data['leave_type_id'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->whereIn('employee_id', Employee::query()->where('department_id', $v)->select('id')))
            ->when(isset($data['change_pending']), fn ($q) => $data['change_pending'] ? $q->whereNotNull('change_request') : $q->whereNull('change_request'))
            // Overlap with the period, by the request's own days (cancelled days included, so history stays findable).
            ->when(isset($data['from']) || isset($data['to']), fn ($q) => $q->whereHas('days', fn ($d) => $d
                ->when($data['from'] ?? null, fn ($x, $v) => $x->where('date', '>=', $v))
                ->when($data['to'] ?? null, fn ($x, $v) => $x->where('date', '<=', $v))));
    }

    private function present(LeaveRequest $row, User $actor): array
    {
        $row->load('days');
        $data = [...$row->toArray(), 'employee_name' => Employee::find($row->employee_id)?->full_name,
            'can_review' => $this->service->mayReview($actor, $row), 'is_owner' => $row->submitted_by === $actor->id,
            'can_act_for_owner' => $this->service->mayActFor($actor, $row)];
        // The reason stays with the employee, their reviewers and organisation-wide HR; department managers see dates only.
        if (! $this->mayReadReason($actor, $row)) {
            $data['input'] = array_diff_key($data['input'] ?? [], ['reason' => true]);
            $data['review_note'] = null;
            $data['change_request'] = $row->change_request === null ? null : array_diff_key($row->change_request, ['reason' => true]);
        }

        return $data;
    }

    public function mayReadReason(User $actor, LeaveRequest $row): bool
    {
        // Reasons: the requester, company-wide leave readers and the deciding reviewer — not department scope.
        return $row->submitted_by === $actor->id || app(Access::class)->covers($actor, 'leaves.view', Scope::ALL) || $this->service->mayReview($actor, $row);
    }

    public function show(Request $request, LeaveRequest $leaveRequest)
    {
        $actor = $request->user();
        abort_unless($leaveRequest->submitted_by === $actor->id || $leaveRequest->entered_by === $actor->id || $this->service->mayReview($actor, $leaveRequest)
            || ($this->scope->hasAnyScope($actor, 'leaves') && $this->scope->canSee($actor, Employee::findOrFail($leaveRequest->employee_id), 'leaves')), 403);

        return response()->json(['data' => $this->present($leaveRequest, $actor)]);
    }

    public function export(Request $request)
    {
        $actor = $request->user();
        abort_unless($request->input('scope', 'mine') === 'mine' || $this->scope->hasAnyScope($actor, 'leaves') || $actor->can('leaves.approve'), 403);
        $rows = $this->filtered($request, $actor)->orderBy('id')->lazyById(200);
        $types = LeaveType::query()->pluck('name', 'id');

        return CsvResponse::download('leave-requests-'.now()->format('Ymd-His').'.csv', [
            'request_id', 'employee_no', 'employee_name', 'department_id', 'leave_type', 'status', 'unit',
            'start_date', 'end_date', 'start_time', 'end_time', 'active_day_units', 'covered_units', 'unpaid_units',
            'submitted_at', 'reviewed_by', 'change_pending', 'reason',
        ], $rows->map(function (LeaveRequest $row) use ($actor, $types) {
            $employee = Employee::find($row->employee_id);
            $active = $row->days()->where('active', true)->get();

            return [$row->id, $employee?->employee_no, $employee?->full_name, $employee?->department_id,
                $types->get($row->leave_type_id), $row->status, $row->input['unit'] ?? null,
                $row->input['start_date'] ?? null, $row->input['end_date'] ?? null,
                $row->input['start_time'] ?? null, $row->input['end_time'] ?? null,
                $active->sum('day_units'), $active->sum('covered_units'), $active->sum('unpaid_units'),
                $row->created_at?->toIso8601String(), $row->reviewed_by, $row->change_request !== null,
                $this->mayReadReason($actor, $row) ? ($row->input['reason'] ?? null) : null];
        }));
    }

    /** Who is off: approved (optionally pending) leave days and office holidays in a period, within the actor's scope. */
    public function calendar(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->scope->hasAnyScope($actor, 'leaves'), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['sometimes', 'integer'], 'include_pending' => ['sometimes', 'boolean'],
        ]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 61, 422, 'الحد الأقصى 62 يوماً.');
        $visible = $this->scope->employeeIds($actor, 'leaves');
        $statuses = ($data['include_pending'] ?? false) ? ['approved', 'pending'] : ['approved'];
        $days = LeaveRequestDay::query()->where('active', true)->whereBetween('date', [$data['from'], $data['to']])
            ->whereIn('leave_request_id', LeaveRequest::query()->whereIn('status', $statuses)->select('id'))
            ->when($visible !== null, fn ($q) => $q->whereIn('employee_id', $visible->all()))
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->whereIn('employee_id', Employee::query()->where('department_id', $v)->select('id')))
            ->orderBy('date')->orderBy('employee_id')->get();
        $requests = LeaveRequest::query()->whereIn('id', $days->pluck('leave_request_id')->unique())->get()->keyBy('id');
        $employees = Employee::query()->whereIn('id', $days->pluck('employee_id')->unique())->get()->keyBy('id');
        $types = LeaveType::query()->pluck('name', 'id');

        return response()->json(['data' => [
            'days' => $days->map(fn ($day) => [
                'date' => substr((string) $day->date, 0, 10), 'employee_id' => $day->employee_id,
                'employee_name' => $employees->get($day->employee_id)?->full_name,
                'department_id' => $employees->get($day->employee_id)?->department_id,
                'request_id' => $day->leave_request_id, 'status' => $requests->get($day->leave_request_id)?->status,
                'leave_type' => ['id' => $requests->get($day->leave_request_id)?->leave_type_id,
                    'name' => $types->get($requests->get($day->leave_request_id)?->leave_type_id)],
                'start_time' => $day->start_time, 'end_time' => $day->end_time,
                'full_day' => (int) $day->day_units === 1_000_000,
            ])->values(),
            'holidays' => OfficeHoliday::query()->whereBetween('date', [$data['from'], $data['to']])->orderBy('date')
                ->get(['id', 'date', 'name'])->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name]),
        ]]);
    }

    public function preview(SubmitLeaveRequest $request, ?LeaveRequest $leaveRequest = null)
    {
        return response()->json(['data' => $this->service->preview($request->user(), $request->validated(), $leaveRequest)]);
    }

    public function store(SubmitLeaveRequest $request, ?LeaveRequest $leaveRequest = null)
    {
        $actor = $request->user();
        $row = $this->service->submit($actor, $request->validated(), $leaveRequest);
        // HR entering a leave that was already agreed can approve it in the same step, if allowed to review it.
        if (! $leaveRequest && $request->boolean('approve_now') && $row->status === 'pending') {
            abort_unless($this->service->mayReview($actor, $row), 403, 'لا تملك صلاحية اعتماد هذا الطلب.');
            $row = $this->service->action($actor, $row, 'approve', ['version' => $row->version, 'reason' => 'اعتماد عند الإدخال من الموارد البشرية']);
        }

        return response()->json(['data' => $this->present($row, $actor)], $leaveRequest ? 200 : 201);
    }

    public function action(Request $request, LeaveRequest $leaveRequest, string $action)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'from_date' => [Rule::requiredIf($action === 'shorten'), 'date_format:Y-m-d']]);
        $row = $this->service->action($request->user(), $leaveRequest, $action, $data);

        return response()->json(['data' => $this->present($row, $request->user())]);
    }

    public function halfDays(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['first_start' => ['required', 'date_format:H:i'],
            'first_end' => ['required', 'date_format:H:i', 'after:first_start'],
            'second_start' => ['required', 'date_format:H:i', 'after_or_equal:first_end'],
            'second_end' => ['required', 'date_format:H:i', 'after:second_start']]);
        DB::transaction(function () use ($data, $audit) {
            $old = (array) DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            DB::table('hr_leave_half_days')->where('id', 1)->update($data);
            $audit->log('leave.half_day_periods_changed', null, $old, $data);
        });

        return response()->json(['data' => $data]);
    }
}
