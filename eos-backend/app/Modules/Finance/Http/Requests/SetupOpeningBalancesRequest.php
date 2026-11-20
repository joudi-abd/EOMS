<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Setup wizard step 7 (spec §12): the opening balances of one branch on the start date. */
class SetupOpeningBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $amount = ['required', 'numeric'];
        $positive = ['required', 'numeric', 'gt:0'];
        $currency = ['required', 'string', 'size:3'];
        $direction = ['required', 'in:owed_to_us,we_owe'];

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:sys_branches,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],

            'boxes' => ['sometimes', 'array'],
            'boxes.*.cash_box_id' => ['required', 'integer'],
            'boxes.*.currency' => $currency,
            'boxes.*.amount' => $amount,

            'projects' => ['sometimes', 'array'],
            'projects.*.project_id' => ['required', 'integer'],
            'projects.*.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'projects.*.receipts' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'projects.*.billed' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'projects.*.costs' => ['sometimes', 'array'],
            'projects.*.costs.*.project_cost_category_id' => ['required', 'integer'],
            'projects.*.costs.*.amount' => ['required', 'numeric', 'gte:0'],

            'agreements' => ['sometimes', 'array'],
            'agreements.*.contractor_agreement_id' => ['required', 'integer'],
            'agreements.*.paid' => $positive,

            'parties' => ['sometimes', 'array'],
            'parties.*.party_id' => ['required', 'integer'],
            'parties.*.currency' => $currency,
            'parties.*.direction' => $direction,
            'parties.*.amount' => $positive,

            'partners' => ['sometimes', 'array'],
            'partners.*.party_id' => ['required', 'integer'],
            'partners.*.currency' => $currency,
            'partners.*.direction' => $direction,
            'partners.*.amount' => $positive,

            'employee_advances' => ['sometimes', 'array'],
            'employee_advances.*.employee_id' => ['required', 'integer'],
            'employee_advances.*.currency' => $currency,
            'employee_advances.*.remaining' => $positive,
            'employee_advances.*.installments_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'employee_advances.*.start_month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'employee_advances.*.installments' => ['sometimes', 'array'],
            'employee_advances.*.installments.*.due_month' => ['required', 'date_format:Y-m'],
            'employee_advances.*.installments.*.amount' => $positive,
            'employee_advances.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'custodies' => ['sometimes', 'array'],
            'custodies.*.holder_party_id' => ['required', 'integer'],
            'custodies.*.project_id' => ['sometimes', 'nullable', 'integer'],
            'custodies.*.currency' => $currency,
            'custodies.*.balance' => $positive,
            'custodies.*.opened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
