<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreProjectFinanceRequest;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Services\Projects\ProjectAccess;
use App\Modules\Finance\Services\Projects\ProjectFinanceService;
use App\Modules\Finance\Services\Projects\ProjectProfitability;
use App\Modules\Finance\Services\Projects\ProjectStatementService;
use App\Modules\Projects\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Project finance (§5.6, §9.4): profile, projects summary (T8), profitability (§6.2) and statement data (T9). */
class ProjectFinanceController extends Controller
{
    public function __construct(private ProjectFinanceService $projects, private ProjectAccess $access, private ProjectProfitability $profitability,
        private ProjectStatementService $statements) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->projects->list($this->filters($request), $request->user()));
    }

    public function summary(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? null;

        return ApiResponse::ok($this->projects->summary($this->filters($request), $request->user(), $date));
    }

    public function show(Request $request, int $project): JsonResponse
    {
        return ApiResponse::ok($this->projects->show($project, $request->user()));
    }

    public function store(StoreProjectFinanceRequest $request): JsonResponse
    {
        return ApiResponse::created($this->projects->create($request->validated(), $request->user()));
    }

    public function update(StoreProjectFinanceRequest $request, int $project): JsonResponse
    {
        return ApiResponse::ok($this->projects->update($project, $request->validated(), $request->user()));
    }

    public function status(Request $request, int $project): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);

        return ApiResponse::ok($this->projects->setStatus($project, $data['status'], $request->user()));
    }

    public function profitability(Request $request, int $project): JsonResponse
    {
        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? null;

        return ApiResponse::ok($this->profitability->for($this->access->readableProfile($request->user(), $project), $date));
    }

    public function statement(Request $request, int $project): JsonResponse
    {
        $data = $request->validate(['variant' => ['sometimes', Rule::in(['internal', 'client'])], 'hide_internal' => ['sometimes', 'boolean'],
            'date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiResponse::ok($this->statements->build($this->access->readableProfile($request->user(), $project), $data['variant'] ?? 'internal',
            $request->boolean('hide_internal'), $data['date'] ?? null));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::in(Project::STATUSES)], 'branch_id' => ['sometimes', 'integer'],
            'client_party_id' => ['sometimes', 'integer'], 'contract_type' => ['sometimes', Rule::in(ProjectProfile::CONTRACT_TYPES)],
            'search' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
    }
}
