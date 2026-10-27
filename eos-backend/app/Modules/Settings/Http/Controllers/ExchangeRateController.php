<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Http\Requests\StoreExchangeRateRequest;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRateService $rates) {}

    public function currencies()
    {
        return ApiResponse::ok($this->rates->currencies());
    }

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

    public function latest(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'exists:sys_currencies,code'],
            'to' => ['required', 'exists:sys_currencies,code'],
        ]);

        return ApiResponse::ok($this->rates->latestRate($data['from'], $data['to']));
    }
}
