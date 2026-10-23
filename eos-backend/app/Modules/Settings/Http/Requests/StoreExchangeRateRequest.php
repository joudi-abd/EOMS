<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'exists:sys_currencies,code'],
            'to' => ['required', 'exists:sys_currencies,code', 'different:from'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['nullable', 'date'],
        ];
    }
}