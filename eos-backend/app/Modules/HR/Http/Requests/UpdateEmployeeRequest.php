<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee')->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('hr_employees', 'email')
                    ->ignore($employeeId),
            ],

            'manager_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('hr_employees', 'id'),
                Rule::notIn([$employeeId]),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_departments,id'],
            'job_title_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_job_titles,id'],
            'sham_cash_wallet' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}