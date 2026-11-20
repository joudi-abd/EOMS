<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreBudgetLineRequest;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Services\Projects\BudgetService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Project budget lines and budget against actual (D10, R13). */
class BudgetLineController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    public function index(Request $request, int $project): JsonResponse
    {
        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? null;

        return ApiResponse::ok($this->budgets->vsActual($project, $request->user(), $date));
    }

    public function store(StoreBudgetLineRequest $request, int $project): JsonResponse
    {
        return ApiResponse::created($this->budgets->create($project, $request->validated(), $request->user()));
    }

    public function update(StoreBudgetLineRequest $request, ProjectBudgetLine $line): JsonResponse
    {
        return ApiResponse::ok($this->budgets->update($line, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ProjectBudgetLine $line): JsonResponse
    {
        $this->budgets->delete($line, $request->user());

        return ApiResponse::message('حُذف بند الموازنة.');
    }
}
