<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A project budget line (D10); category and currency are fixed after creation. */
class StoreBudgetLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'project_cost_category_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:fin_project_cost_categories,id'],
            'currency_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:sys_currencies,id'],
            'budget_amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:999999999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
