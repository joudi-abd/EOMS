<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A month's time shares for some employees: per employee, a percentage per project (total ≤ 100). */
class SetTimeSharesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'employees' => ['required', 'array', 'min:1', 'max:1000'],
            'employees.*.employee_id' => ['required', 'integer', 'distinct'],
            'employees.*.shares' => ['present', 'array', 'max:50'],
            'employees.*.shares.*.project_id' => ['required', 'integer'],
            'employees.*.shares.*.percentage' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
        ];
    }
}
