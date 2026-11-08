<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\SubmitLeaveRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\LeaveWorkflowService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveWorkflowService $service) {}

    public function options(Request $request)
    {
        return response()->json(['data' => $this->service->options($request->user())]);
    }

    public function index(Request $request)
    {
        $request->validate(['scope' => ['sometimes', Rule::in(['mine', 'review'])], 'page' => ['sometimes', 'integer', 'min:1']]);
        $actor = $request->user();
        $query = LeaveRequest::query();
        if ($request->input('scope') === 'review') {
            abort_unless($actor->can('leaves.approve') && $actor->hasAnyRole(['company_manager', 'hr_manager']), 403);
            $hrIds = User::role('hr_manager')->select('usr_users.id');
            if (! $actor->hasRole('company_manager')) {
                $query->where('approver_role', 'hr_manager')->whereNotIn('submitted_by', $hrIds);
            } elseif (! $actor->hasRole('hr_manager')) {
                $query->where(fn ($q) => $q->where('approver_role', 'company_manager')->orWhereIn('submitted_by', $hrIds));
            }
            $query->where('submitted_by', '!=', $actor->id);
        } else {
            $query->where('submitted_by', $actor->id);
        }
        $page = $query->orderByDesc('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row, $actor)));
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    private function present(LeaveRequest $row, User $actor): array
    {
        $row->load('days');
        return [...$row->toArray(), 'employee_name' => Employee::find($row->employee_id)?->full_name,
            'can_review' => $this->service->mayReview($actor, $row), 'is_owner' => $row->submitted_by === $actor->id];
    }

    public function show(Request $request, LeaveRequest $leaveRequest)
    {
        abort_unless($leaveRequest->submitted_by === $request->user()->id || $this->service->mayReview($request->user(), $leaveRequest), 403);
        return response()->json(['data' => $this->present($leaveRequest, $request->user())]);
    }

    public function preview(SubmitLeaveRequest $request, ?LeaveRequest $leaveRequest = null)
    {
        return response()->json(['data' => $this->service->preview($request->user(), $request->validated(), $leaveRequest)]);
    }

    public function store(SubmitLeaveRequest $request, ?LeaveRequest $leaveRequest = null)
    {
        $row = $this->service->submit($request->user(), $request->validated(), $leaveRequest);
        return response()->json(['data' => $this->present($row, $request->user())], $leaveRequest ? 200 : 201);
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
