<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Http\Requests\StoreExchangeRateRequest;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('06 - Settings / Exchange Rates', weight: 6)]
class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRateService $rates) {}

    /**
     * List exchange rates.
     *
     * Returns a paginated exchange rate list.
     * Supports searching and filtering.
     */
    public function currencies()
    {
        return ApiResponse::ok($this->rates->currencies());
    }

    /**
     * Create an exchange rate.
     *
     * Creates an exchange rate record for a specific
     * currency pair and date.
     */
    public function store(StoreExchangeRateRequest $request)
    {
        $data = $request->validated();

        $rate = $this->rates->setRate(
            $data['from'],
            $data['to'],
            (float) $data['rate'],
            $data['rate_date'] ?? now()->toDateString()
        );

        return ApiResponse::created($rate);
    }

    /**
     * Get the latest exchange rate.
     *
     * Returns the most recent exchange rate for a
     * specific currency pair.
     */
    public function latest(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'exists:sys_currencies,code'],
            'to' => ['required', 'exists:sys_currencies,code'],
        ]);

        return ApiResponse::ok($this->rates->latestRate($data['from'], $data['to']));
    }
}
