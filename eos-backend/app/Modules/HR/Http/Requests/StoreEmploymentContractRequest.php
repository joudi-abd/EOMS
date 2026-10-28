<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmploymentContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_number' => ['required', 'string', 'max:50', 'unique:hr_employment_contracts,contract_number'],
            'type' => ['required', Rule::in(['fixed_term', 'indefinite'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_if:type,fixed_term', 'prohibited_if:type,indefinite'],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'required_with:currency_code'],
            'currency_code' => ['nullable', 'string', 'size:3', 'required_with:base_salary'],
        ];
    }
}
