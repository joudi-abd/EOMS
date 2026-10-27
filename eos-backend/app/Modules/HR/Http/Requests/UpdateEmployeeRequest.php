<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('hr_employees', 'email')->ignore($employee->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30',
                Rule::unique('usr_users', 'phone')->ignore($employee->user_id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_departments,id'],
            'job_title_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_job_titles,id'],
            'manager_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('hr_employees', 'id'), Rule::notIn([$employee->id])],
            'sham_cash_wallet' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}
