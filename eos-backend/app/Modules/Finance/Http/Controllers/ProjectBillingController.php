<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreProjectBillingRequest;
use App\Modules\Finance\Services\Projects\ProjectBillingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Project billings (#2): the suggestion (§6.1), the list and a new billing. */
class ProjectBillingController extends Controller
{
    public function __construct(private ProjectBillingService $billings) {}

    public function index(Request $request, int $project): JsonResponse
    {
        return ApiResponse::ok($this->billings->list($project, $request->user()));
    }

    public function suggestion(Request $request, int $project): JsonResponse
    {
        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? null;

        return ApiResponse::ok($this->billings->suggest($project, $request->user(), $date));
    }

    public function store(StoreProjectBillingRequest $request, int $project): JsonResponse
    {
        $data = $request->validated();
        $data['approve'] = $request->boolean('approve', true);

        return ApiResponse::created($this->billings->create($project, $data, $request->user()));
    }
}
