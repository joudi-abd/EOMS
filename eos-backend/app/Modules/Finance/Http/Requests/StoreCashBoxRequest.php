<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Models\CashBox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'type' => [$required, Rule::in(CashBox::TYPES)],
            'branch_id' => [$required, 'integer', 'exists:sys_branches,id'],
            'custodian_user_id' => ['nullable', 'integer', 'exists:usr_users,id'],
            'contact_info' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'currencies' => [$required, 'array', 'min:1', 'max:30'],
            'currencies.*.currency_id' => ['required_without:currencies.*.currency', 'nullable', 'integer'],
            'currencies.*.currency' => ['required_without:currencies.*.currency_id', 'nullable', 'string', 'size:3'],
            'currencies.*.allow_negative' => ['sometimes', 'boolean'],
            'currencies.*.negative_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
        ];
    }
}
