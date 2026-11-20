<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreProjectCostCategoryRequest;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Services\Projects\ProjectCostCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProjectCostCategoryController extends Controller
{
    public function __construct(private ProjectCostCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        return ApiResponse::ok($this->categories->list($request->has('active') ? $request->boolean('active') : null));
    }

    public function store(StoreProjectCostCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created($this->categories->create($request->validated(), $request->user()));
    }

    public function update(StoreProjectCostCategoryRequest $request, ProjectCostCategory $category): JsonResponse
    {
        return ApiResponse::ok($this->categories->update($category, $request->validated(), $request->user()));
    }
}
