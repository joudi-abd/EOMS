<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveBalanceEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:hr_leave_types,id'],
            'kind' => ['required', Rule::in(['opening', 'adjustment'])],
            'days' => ['required', 'regex:/^-?\d{1,4}(\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
