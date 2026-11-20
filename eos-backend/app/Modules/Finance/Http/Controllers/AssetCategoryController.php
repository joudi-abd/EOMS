<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreAssetCategoryRequest;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Finance\Services\Assets\AssetCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The asset category tree: read with `finance.assets`, managed with `finance.settings`. */
class AssetCategoryController extends Controller
{
    public function __construct(private AssetCategoryService $categories) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->categories->list());
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created($this->categories->create($request->validated(), $request->user()));
    }

    public function update(StoreAssetCategoryRequest $request, AssetCategory $category): JsonResponse
    {
        return ApiResponse::ok($this->categories->update($category, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, AssetCategory $category): JsonResponse
    {
        $this->categories->delete($category, $request->user());

        return ApiResponse::message('تم حذف التصنيف.');
    }
}
