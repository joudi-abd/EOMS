<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Expense category payload (D21). Tree and account rules are checked in ExpenseCategoryService. */
class StoreExpenseCategoryRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', 'exists:fin_expense_categories,id'],
            'account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'is_overhead_allocatable' => ['sometimes', 'boolean'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
