<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Maintenance of an asset paid from a box (#23), optionally charged to a project. */
class AssetMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_box_id' => ['required', 'integer', 'exists:fin_cash_boxes,id'],
            'currency_id' => ['required_without:currency', 'nullable', 'integer', 'exists:sys_currencies,id'],
            'currency' => ['required_without:currency_id', 'nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999'],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:500'],
            'project_id' => ['nullable', 'integer', 'exists:prj_projects,id'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'approve' => ['sometimes', 'boolean'],
        ];
    }
}
