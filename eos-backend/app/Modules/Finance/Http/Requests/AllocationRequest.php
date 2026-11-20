<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An allocation to preview or post (§6.3): kind, month, branch, method and the method's lines. */
class AllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['payroll', 'overhead'])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => [Rule::requiredIf($this->input('kind') === 'overhead'), 'nullable', 'integer', 'exists:sys_branches,id'],
            'method' => ['required', Rule::in(['time_shares', 'percentages', 'manual_amounts', 'proportional_direct_cost'])],
            'lines' => [Rule::requiredIf(in_array($this->input('method'), ['percentages', 'manual_amounts'], true)), 'array', 'max:500'],
            'lines.*.project_id' => ['required', 'integer'],
            'lines.*.percentage' => ['required_if:method,percentages', 'nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'lines.*.amount' => ['required_if:method,manual_amounts', 'nullable', 'numeric', 'gt:0', 'max:999999999999999'],
            'lines.*.currency' => ['required_if:method,manual_amounts', 'nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
