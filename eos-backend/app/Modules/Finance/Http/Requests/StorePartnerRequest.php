<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Partner payload. `other_shares` (partner id => share) changes the other partners' shares in the same call (D16). */
class StorePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'party_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:fin_parties,id'],
            'share_pct' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'joined_at' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'other_shares' => ['sometimes', 'array'],
            'other_shares.*' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
