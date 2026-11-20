<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Services\Parties\PartyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Party payload (create and update). The HR link and role rules are checked in PartyService. */
class StorePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'roles' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(Party::ROLES)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['nullable', 'integer'],
            'contractor_tracking_mode' => ['nullable', Rule::in(PartyService::TRACKING_MODES)],
            'trade' => ['nullable', 'string', 'max:100'],
            'sham_cash_wallet' => ['nullable', 'string', 'max:16'],
            'sham_cash_name' => ['nullable', 'string', 'max:150'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
