<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A project with its finance profile (create) or changes to either (PATCH). Contract rules per type live in
 * ProjectFinanceService. `project_id` on create links an existing general project instead of creating one.
 */
class StoreProjectFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $needsProject = $creating && ! $this->filled('project_id') ? 'required' : 'sometimes';

        return [
            'project_id' => [$creating ? 'nullable' : 'prohibited', 'integer', 'exists:prj_projects,id'],
            'name' => [$needsProject, 'string', 'max:200'],
            'status' => [$creating ? 'sometimes' : 'prohibited', Rule::in(Project::STATUSES)],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:sys_branches,id'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:usr_users,id'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'client_party_id' => ['sometimes', 'nullable', 'integer', 'exists:fin_parties,id'],
            'contract_type' => [$creating ? 'required' : 'sometimes', Rule::in(ProjectProfile::CONTRACT_TYPES)],
            'contract_currency_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:sys_currencies,id'],
            'contract_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'fee_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'fee_base_category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'fee_base_category_ids.*' => ['integer', 'distinct', 'exists:fin_project_cost_categories,id'],
        ];
    }
}
