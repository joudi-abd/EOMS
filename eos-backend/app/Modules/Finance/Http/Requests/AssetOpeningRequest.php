<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Services\Assets\AssetGuard;
use Illuminate\Validation\Rule;

/** An existing asset entered as an opening balance (spec §12 step 7): no box; `date` is the opening date. */
class AssetOpeningRequest extends AssetPurchaseRequest
{
    public function rules(): array
    {
        return [
            ...$this->assetRules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(AssetGuard::ACTIVE)],
        ];
    }
}
