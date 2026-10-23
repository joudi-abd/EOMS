<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ExchangeRateController extends Controller
{
    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::orderBy('code')->get()]);
    }

    public function store(Request $request, ExchangeRateService $service): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'exists:sys_currencies,code'],
            'to' => ['required', 'exists:sys_currencies,code', 'different:from'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['nullable', 'date'],
        ]);

        $rate = $service->setRate(
            $data['from'],
            $data['to'],
            (float) $data['rate'],
            $data['rate_date'] ?? now()->toDateString()
        );

        return response()->json(['data' => $rate], 201);
    }

    public function latest(Request $request, ExchangeRateService $service): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'exists:sys_currencies,code'],
            'to' => ['required', 'exists:sys_currencies,code'],
        ]);

        return response()->json(['data' => $service->latestRate($data['from'], $data['to'])]);
    }
}