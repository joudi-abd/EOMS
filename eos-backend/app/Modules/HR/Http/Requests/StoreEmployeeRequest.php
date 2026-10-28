<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'father_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'national_id' => ['required', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255', 'unique:hr_employees,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:usr_users,phone'],
            'address' => ['nullable', 'string', 'max:500'],
            'department_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
            'job_title_id' => ['nullable', 'integer', 'exists:hr_job_titles,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'sham_cash_wallet' => ['nullable', 'string', 'max:30'],
            'hire_date' => ['required', 'date'],
            'account' => ['prohibited'],
        ];
    }
}
