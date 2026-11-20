<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\AllocationRequest;
use App\Modules\Finance\Http\Requests\SetTimeSharesRequest;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Services\Allocations\AllocationService;
use App\Modules\Finance\Services\Allocations\TimeShareService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Payroll time shares and allocations of payroll (#17) and overhead (#18) to projects. */
class AllocationController extends Controller
{
    public function __construct(private TimeShareService $shares, private AllocationService $allocations) {}

    public function timeShares(Request $request): JsonResponse
    {
        $data = $request->validate($this->monthRules());

        return ApiResponse::ok($this->shares->month((int) $data['year'], (int) $data['month']));
    }

    public function setTimeShares(SetTimeSharesRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::ok($this->shares->set((int) $data['year'], (int) $data['month'], $data['employees'], $request->user()));
    }

    public function copyTimeShares(Request $request): JsonResponse
    {
        $data = $request->validate($this->monthRules());

        return ApiResponse::ok($this->shares->copyPrevious((int) $data['year'], (int) $data['month'], $request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in(['payroll', 'overhead'])], 'year' => ['sometimes', 'integer'], 'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['sometimes', 'integer'], 'status' => ['sometimes', Rule::in(['approved', 'reversed'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated($this->allocations->list($filters, $request->user()));
    }

    public function show(Allocation $allocation, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->allocations->show($allocation, $request->user()));
    }

    public function preview(AllocationRequest $request): JsonResponse
    {
        return ApiResponse::ok($this->allocations->preview($request->validated(), $request->user()));
    }

    public function store(AllocationRequest $request): JsonResponse
    {
        return ApiResponse::created($this->allocations->create($request->validated(), $request->user()));
    }

    private function monthRules(): array
    {
        return ['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12']];
    }
}
