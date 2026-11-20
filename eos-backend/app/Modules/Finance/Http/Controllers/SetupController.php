<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\SetupOpeningBalancesRequest;
use App\Modules\Finance\Services\Setup\OpeningBalanceService;
use App\Modules\Finance\Services\Setup\SetupWizardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The setup wizard (spec §12, phase 11). */
class SetupController extends Controller
{
    public function __construct(private SetupWizardService $wizard) {}

    /** The eight steps with done / pending and what is missing. */
    public function status(): JsonResponse
    {
        return ApiResponse::ok($this->wizard->status());
    }

    /** The starter chart, only on an empty chart (409 otherwise). */
    public function starterChart(Request $request): JsonResponse
    {
        return ApiResponse::created($this->wizard->starterChart($request->user()));
    }

    /** Step 7: one branch's opening balances on the start date, posted. */
    public function openingBalances(SetupOpeningBalancesRequest $request, OpeningBalanceService $openings): JsonResponse
    {
        return ApiResponse::created($openings->post($request->validated(), $request->user()));
    }

    /** Step 8: the opening trial balance per currency and opening_balance_equity against net assets. */
    public function openingCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiResponse::ok($this->wizard->openingCheck($data['date'] ?? null));
    }
}
