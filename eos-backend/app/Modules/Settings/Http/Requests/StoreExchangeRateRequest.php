<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** One day's bulletin: `rates` maps a currency code to how many units buy one base unit (USD). */
class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
