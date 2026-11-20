<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Reports\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    /** The general manager's dashboard (spec §10.1) within the reader's `finance.reports` reach. */
    public function show(Request $request, DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::ok($dashboard->build($request->user()));
    }
}
