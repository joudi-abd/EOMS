<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Services\Assets\AssetOperationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sale (#24), scrapping or loss (#25) of an asset. A sale needs a price and the receiving box. */
class AssetDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AssetOperationService::DISPOSAL_TYPES)],
            'date' => ['required', 'date_format:Y-m-d'],
            'sale_amount' => ['required_if:type,sale', 'prohibited_unless:type,sale', 'nullable', 'numeric', 'gt:0', 'max:999999999999999'],
            'cash_box_id' => ['required_if:type,sale', 'prohibited_unless:type,sale', 'nullable', 'integer', 'exists:fin_cash_boxes,id'],
            'currency_id' => ['nullable', 'integer', 'exists:sys_currencies,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'approve' => ['sometimes', 'boolean'],
        ];
    }
}
