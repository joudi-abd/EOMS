<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Services\Projects\ContractorAgreementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A contractor agreement (§5.6); net_value is computed by the service. */
class StoreContractorAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'project_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:prj_projects,id'],
            'contractor_party_id' => [$required, 'integer', 'exists:fin_parties,id'],
            'scope' => [$required, 'string', 'max:300'],
            'project_cost_category_id' => ['sometimes', 'nullable', 'integer', 'exists:fin_project_cost_categories,id'],
            'currency_id' => [$required, 'integer', 'exists:sys_currencies,id'],
            'agreed_value' => [$required, 'numeric', 'min:0', 'max:999999999999999'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'status' => ['sometimes', Rule::in(ContractorAgreementService::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
