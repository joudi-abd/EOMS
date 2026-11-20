<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Custodies (§5.7, #19–#21): list, history, T16 open custodies with age, and the supervisor's "عهدتي" (§9.6). Moved through POST /vouchers. */
class CustodyController extends Controller
{
    public function __construct(private CustodyService $custodies) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['holder_party_id' => ['sometimes', 'integer'], 'project_id' => ['sometimes', 'integer'],
            'currency_id' => ['sometimes', 'integer'], 'status' => ['sometimes', Rule::in(['open', 'closed'])], 'overdue' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated($this->custodies->list($filters, $request->user()));
    }

    public function show(Request $request, Custody $custody): JsonResponse
    {
        return ApiResponse::ok($this->custodies->show($custody, $request->user()));
    }

    /** T16: open custodies with their age. */
    public function report(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->custodies->openReport($request->user()));
    }

    /** The signed-in holder's own custodies, balances and last movements. */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->custodies->mine($request->user()));
    }
}
