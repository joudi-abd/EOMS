<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitLeaveRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $preview = $this->routeIs('leaves.preview', 'leaves.preview-edit');
        $editing = $this->route('leaveRequest') !== null;
        return [
            'leave_type_id' => [Rule::requiredIf(! $editing), 'integer', 'exists:hr_leave_types,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'unit' => ['required', Rule::in(['day', 'half', 'hours'])],
            'half' => ['required_if:unit,half', 'nullable', Rule::in(['first', 'second'])],
            'start_time' => ['required_if:unit,hours', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_if:unit,hours', 'nullable', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'max:500'],
            // HR (leaves.manage) entering leave for another employee; ignored when editing an existing request.
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_employees,id'],
            'approve_now' => ['sometimes', 'boolean'],
            'unpaid_consent' => ['sometimes', 'boolean'],
            'quote_token' => [Rule::requiredIf(! $preview), 'string', 'size:64'],
            'submission_key' => [Rule::requiredIf(! $preview && ! $editing), 'uuid'],
            'version' => [Rule::requiredIf(! $preview && $editing), 'integer', 'min:1'],
        ];
    }
}
