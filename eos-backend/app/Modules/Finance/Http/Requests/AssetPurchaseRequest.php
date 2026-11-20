<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Purchase of an asset from the asset screen (#22): the asset's fields plus the paying box. */
class AssetPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->assetRules(),
            'cash_box_id' => ['required', 'integer', 'exists:fin_cash_boxes,id'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'approve' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** Fields shared by every way an asset is created. */
    protected function assetRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:fin_asset_categories,id'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:999999999'],
            'unit_cost' => ['required', 'numeric', 'gt:0', 'max:999999999999999'],
            'currency_id' => ['required_without:currency', 'nullable', 'integer', 'exists:sys_currencies,id'],
            'currency' => ['required_without:currency_id', 'nullable', 'string', 'size:3'],
            'holder_employee_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer', 'exists:sys_branches,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
