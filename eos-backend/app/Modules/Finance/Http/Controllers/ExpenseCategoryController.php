<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Services\Expenses\ExpenseCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Expense category tree (D21, spec §5.5). */
class ExpenseCategoryController extends Controller
{
    public function __construct(private ExpenseCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        return ApiResponse::ok($this->categories->tree($request->has('active') ? $request->boolean('active') : null));
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created($this->categories->create($this->normalised($request), $request->user()));
    }

    public function update(StoreExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        return ApiResponse::ok($this->categories->update($category, $this->normalised($request), $request->user()));
    }

    public function deactivate(Request $request, ExpenseCategory $category): JsonResponse
    {
        return ApiResponse::ok($this->categories->deactivate($category, $request->user()));
    }

    private function normalised(StoreExpenseCategoryRequest $request): array
    {
        $data = $request->validated();
        foreach (['is_active', 'is_overhead_allocatable'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return $data;
    }
}
