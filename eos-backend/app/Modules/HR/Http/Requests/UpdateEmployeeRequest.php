<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'father_name' => ['sometimes', 'required', 'string', 'max:80'],
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
            'effective_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'reason' => [
                'sometimes',
                'required',
                'string',
                'max:500',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Employee $employee */
            $employee = $this->route('employee');

            $assignmentFields = [
                'department_id',
                'job_title_id',
                'manager_id',
            ];

            $hasAssignmentChange = false;

            foreach ($assignmentFields as $field) {
                if (! $this->exists($field)) {
                    continue;
                }

                $newValue = $this->input($field);
                $oldValue = $employee->{$field};

                if ($this->normalizeId($newValue) !== $this->normalizeId($oldValue)) {
                    $hasAssignmentChange = true;
                    break;
                }
            }

            if (! $hasAssignmentChange) {
                if ($this->exists('effective_date') || $this->exists('reason')) {
                    $validator->errors()->add(
                        'reason',
                        'تاريخ وسبب التغيير يُرسلان فقط عند تغيير البيانات الوظيفية.'
                    );
                }

                return;
            }

            if (! $this->filled('effective_date')) {
                $validator->errors()->add(
                    'effective_date',
                    'تاريخ سريان التغيير الوظيفي مطلوب.'
                );
            }

            if (! $this->filled('reason')) {
                $validator->errors()->add(
                    'reason',
                    'سبب التغيير الوظيفي مطلوب.'
                );
            }

            if ($this->filled('effective_date')
                && $employee->hire_date
                && $this->input('effective_date') < $employee->hire_date->format('Y-m-d')
            ) {
                $validator->errors()->add(
                    'effective_date',
                    'تاريخ السريان لا يمكن أن يسبق تاريخ تعيين الموظف.'
                );
            }
        });
    }

    private function normalizeId(mixed $value): ?int
    {
        return $value === null || $value === ''
            ? null
            : (int) $value;
    }
}
