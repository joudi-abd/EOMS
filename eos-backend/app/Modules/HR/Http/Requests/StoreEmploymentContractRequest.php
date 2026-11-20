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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency_code'))) {
            $this->merge(['currency_code' => strtoupper($this->input('currency_code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'contract_number' => ['required', 'string', 'max:50', 'unique:hr_employment_contracts,contract_number'],
            'type' => ['required', Rule::in(['fixed_term', 'indefinite'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_if:type,fixed_term', 'prohibited_if:type,indefinite'],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2', 'required_with:currency_code'],
            // Salaries are paid in the payroll currencies managed in settings (D20).
            'currency_code' => ['nullable', 'string', 'size:3', 'required_with:base_salary',
                Rule::exists('sys_currencies', 'code')->where('is_payroll_currency', true)->where('is_active', true)],
            'pay_type' => ['sometimes', Rule::in(['monthly', 'daily', 'hourly'])],
        ];
    }
}
