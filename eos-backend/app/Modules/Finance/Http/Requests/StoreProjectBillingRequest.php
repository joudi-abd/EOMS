<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A project billing (#2) from the project screen: the amount the accountant settled on after the suggestion (§6.1). */
class StoreProjectBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999'],
            'voucher_date' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'approve' => ['sometimes', 'boolean'],
        ];
    }
}
