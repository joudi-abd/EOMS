<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\CurrencyService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

#[Group('06 - Settings / Exchange Rates', weight: 6)]
class CurrencyController extends Controller
{
    public function __construct(private CurrencyService $currencies) {}

    /**
     * List currencies.
     *
     * Base currency first. `active=1` limits to active ones.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($request->boolean('active') ? $this->currencies->active() : $this->currencies->list());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('sys_currencies', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'rounding_step' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'is_base' => ['sometimes', 'boolean'],
            'is_payroll_currency' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::created($this->currencies->create($data));
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'symbol' => ['sometimes', 'string', 'max:10'],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'rounding_step' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'is_base' => ['sometimes', 'boolean'],
            'is_payroll_currency' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        foreach (['is_base', 'is_payroll_currency', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return ApiResponse::ok($this->currencies->update($currency, $data));
    }
}
