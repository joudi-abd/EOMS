<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StorePartyRequest;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Parties\PartyService;
use App\Modules\Finance\Services\Parties\PartyStatementService;
use App\Modules\Settings\Models\Currency;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Parties (D18): list by role, card with balances, statement (T12), create/update/deactivate, CSV import. */
class PartyController extends Controller
{
    public function __construct(private PartyService $parties, private PartyStatementService $statements, private FinanceScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['role' => ['sometimes', Rule::in(Party::ROLES)], 'search' => ['sometimes', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'], 'employee_id' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        if (array_key_exists('active', $filters)) {
            $filters['active'] = $request->boolean('active');
        }

        return ApiResponse::paginated($this->parties->list($filters));
    }

    public function show(Request $request, Party $party): JsonResponse
    {
        return ApiResponse::ok($this->parties->present($party, $this->scope->branchIds($request->user(), 'finance.view')));
    }

    /** Party statement per currency with opening, running and closing balances (spec §9.3). */
    public function statement(Request $request, Party $party): JsonResponse
    {
        $data = $request->validate(['currency' => ['sometimes', 'string', 'exists:sys_currencies,code'], 'currency_id' => ['sometimes', 'integer', 'exists:sys_currencies,id'],
            'project_id' => ['sometimes', 'integer'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $currencyId = $data['currency_id'] ?? (isset($data['currency']) ? (int) Currency::query()->where('code', strtoupper($data['currency']))->value('id') : null);

        return ApiResponse::ok($this->statements->statement($party, ['currency_id' => $currencyId, 'project_id' => $data['project_id'] ?? null,
            'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null], $this->scope->branchIds($request->user(), 'finance.view')));
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        $party = $this->parties->create($request->validated(), $request->user());

        return ApiResponse::created($this->parties->present($party));
    }

    public function update(StorePartyRequest $request, Party $party): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return ApiResponse::ok($this->parties->present($this->parties->update($party, $data, $request->user())));
    }

    public function deactivate(Request $request, Party $party): JsonResponse
    {
        return ApiResponse::ok($this->parties->present($this->parties->deactivate($party, $request->user())));
    }

    /** CSV (name, phone, role, trade) — setup wizard step 5. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        return ApiResponse::ok($this->parties->importCsv($request->file('file'), $request->user()));
    }
}
