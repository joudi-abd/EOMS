<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Http\Requests\StoreExchangeRateRequest;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('06 - Settings / Exchange Rates', weight: 6)]
class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRateService $rates) {}

    /**
     * The bulletin of one day.
     *
     * Rates against the base currency (units per 1 USD) entered for `date` (default today), and the active
     * currencies still missing from it.
     */
    public function bulletin(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiResponse::ok($this->rates->bulletin($data['date'] ?? now()->toDateString()));
    }

    /**
     * Rate history of one currency, newest first.
     */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'exists:sys_currencies,code'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        return ApiResponse::ok($this->rates->history($data['currency'], (int) ($data['limit'] ?? 30)));
    }

    /**
     * Save a day's bulletin.
     *
     * Body: `{date, rates: {TRY: 43.4, SYP: 13000}}`. Existing rates of that day are replaced.
     */
    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::created($this->rates->setRates($data['date'], $data['rates'], $request->user()->id));
    }

    /**
     * Copy the previous rates into a day.
     *
     * Every active currency without a rate on `date` gets its latest earlier rate.
     */
    public function copyPrevious(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::ok($this->rates->copyPrevious($data['date'], $request->user()->id));
    }
}
