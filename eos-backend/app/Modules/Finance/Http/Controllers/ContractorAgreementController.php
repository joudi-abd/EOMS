<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreContractorAgreementRequest;
use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Services\Projects\ContractorAgreementService;
use App\Modules\Finance\Services\Projects\ProjectAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Contractor agreements (§5.6, D11) with their payments and advances. */
class ContractorAgreementController extends Controller
{
    public function __construct(private ContractorAgreementService $agreements, private ProjectAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['project_id' => ['sometimes', 'integer'], 'party_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(ContractorAgreementService::STATUSES)], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated($this->agreements->list($filters, $request->user()));
    }

    public function show(Request $request, ContractorAgreement $agreement): JsonResponse
    {
        $this->access->readable($request->user(), $agreement->project_id);

        return ApiResponse::ok($this->agreements->present($agreement));
    }

    public function store(StoreContractorAgreementRequest $request): JsonResponse
    {
        return ApiResponse::created($this->agreements->present($this->agreements->create($request->validated(), $request->user())));
    }

    public function update(StoreContractorAgreementRequest $request, ContractorAgreement $agreement): JsonResponse
    {
        return ApiResponse::ok($this->agreements->present($this->agreements->update($agreement, $request->validated(), $request->user())));
    }

    public function destroy(Request $request, ContractorAgreement $agreement): JsonResponse
    {
        $this->agreements->delete($agreement, $request->user());

        return ApiResponse::message('حُذفت الاتفاقية.');
    }
}
