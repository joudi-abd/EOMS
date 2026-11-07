<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tracked = $this->filled('annual_days');
        $monthly = $tracked && $this->input('grant_method') === 'monthly';
        $fixed = $tracked && $this->input('year_basis') === 'fixed';

        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'pay_percent' => ['required', 'integer', 'between:0,100'],
            'annual_days' => ['nullable', 'numeric', 'min:0', 'max:366', 'decimal:0,6'],
            'grant_method' => [Rule::requiredIf($tracked), Rule::prohibitedIf(! $tracked), 'nullable', Rule::in(['upfront', 'monthly'])],
            'year_basis' => [Rule::requiredIf($tracked), Rule::prohibitedIf(! $tracked), 'nullable', Rule::in(['fixed', 'anniversary'])],
            'year_start' => [Rule::requiredIf($fixed), Rule::prohibitedIf(! $fixed), 'nullable', 'bail', 'string', 'regex:/^\d{2}-\d{2}$/',
                function ($attribute, $value, $fail) {
                    [$month, $day] = array_pad(explode('-', $value, 2), 2, 0);
                    if (! checkdate((int) $month, (int) $day, 2001)) {
                        $fail('أدخل تاريخ بداية سنوي صالحاً بصيغة شهر-يوم.');
                    }
                }],
            'first_year_method' => [Rule::requiredIf($monthly), Rule::prohibitedIf(! $monthly), 'nullable', Rule::in(['upfront', 'remaining'])],
            'retroactive_days' => ['required', 'integer', 'between:0,366'],
        ];
    }
}
