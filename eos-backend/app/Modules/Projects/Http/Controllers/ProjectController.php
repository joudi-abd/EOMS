<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Services\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'branch_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->projects->visibleTo($request->user())
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['branch_id'] ?? null, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($page);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        $row = $this->projects->visibleTo($request->user())->with('members')->find($project);
        abort_if($row === null, 404, 'المشروع غير موجود.');

        return ApiResponse::ok($row);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created($this->projects->create($this->validated($request, true), $request->user()));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        return ApiResponse::ok($this->projects->update($project, $this->validated($request, false), $request->user()));
    }

    public function status(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);

        return ApiResponse::ok($this->projects->setStatus($project, $data['status'], $request->user()));
    }

    public function members(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'members' => ['present', 'array', 'max:200'],
            'members.*.user_id' => ['required', 'integer', 'distinct', 'exists:usr_users,id'],
            'members.*.role' => ['required', Rule::in(ProjectMember::ROLES)],
        ]);

        return ApiResponse::ok($this->projects->syncMembers($project, $data['members'], $request->user()));
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:usr_users,id'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'members' => ['sometimes', 'array', 'max:200'],
            'members.*.user_id' => ['required', 'integer', 'distinct', 'exists:usr_users,id'],
            'members.*.role' => ['required', Rule::in(ProjectMember::ROLES)],
        ]);
    }
}
