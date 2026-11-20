<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\PostponeInstallmentRequest;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Employee advances (D17, #14): list, schedule, postponing an installment (R18), T15 data. Issued through POST /vouchers. */
class EmployeeAdvanceController extends Controller
{
    public function __construct(private EmployeeAdvanceService $advances) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['employee_id' => ['sometimes', 'integer'], 'status' => ['sometimes', Rule::in(['active', 'settled', 'cancelled'])],
            'currency' => ['sometimes', 'string', 'size:3'], 'currency_id' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated($this->advances->list($filters, $request->user()));
    }

    public function show(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        return ApiResponse::ok($this->advances->show($advance, $request->user()));
    }

    /** T15: balances per employee and currency, and the installments due in the coming months. */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => ['sometimes', 'integer', 'min:1', 'max:24']]);

        return ApiResponse::ok($this->advances->report($request->user(), (int) ($data['months'] ?? 3)));
    }

    public function postpone(PostponeInstallmentRequest $request, EmployeeAdvanceInstallment $installment): JsonResponse
    {
        $advance = EmployeeAdvance::query()->findOrFail($installment->advance_id);
        $this->advances->assertReach($advance, $request->user(), 'finance.vouchers');
        $this->advances->postpone($installment->id, $request->validated('reason'), $request->user());

        return ApiResponse::ok($this->advances->show($advance, $request->user()));
    }
}
