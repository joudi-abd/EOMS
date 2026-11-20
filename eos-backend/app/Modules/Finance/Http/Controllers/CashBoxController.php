<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreCashBoxRequest;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Treasury\CashBoxService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CashBoxController extends Controller
{
    public function __construct(private CashBoxService $boxes, private FinanceScope $scope) {}

    /** Boxes in the caller's branches, each currency with balance, `negative` and `near_limit` flags. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['branch_id' => ['sometimes', 'integer'], 'active' => ['sometimes', 'boolean']]);
        if (array_key_exists('active', $filters)) {
            $filters['active'] = $request->boolean('active');
        }

        return ApiResponse::ok($this->boxes->list($request->user(), $filters));
    }

    public function show(Request $request, CashBox $box): JsonResponse
    {
        $this->scope->assertBranch($request->user(), 'finance.view', $box->branch_id);

        return ApiResponse::ok($this->boxes->present($box));
    }

    public function store(StoreCashBoxRequest $request): JsonResponse
    {
        return ApiResponse::created($this->boxes->present($this->boxes->create($this->normalised($request), $request->user())));
    }

    public function update(StoreCashBoxRequest $request, CashBox $box): JsonResponse
    {
        return ApiResponse::ok($this->boxes->present($this->boxes->update($box, $this->normalised($request), $request->user())));
    }

    public function deactivate(Request $request, CashBox $box): JsonResponse
    {
        return ApiResponse::ok($this->boxes->present($this->boxes->deactivate($box, $request->user())));
    }

    private function normalised(StoreCashBoxRequest $request): array
    {
        $data = $request->validated();
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }
        foreach ($data['currencies'] ?? [] as $i => $row) {
            $data['currencies'][$i]['allow_negative'] = filter_var($row['allow_negative'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }
}
