<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a voucher payload (create and draft update). Type-specific business rules live in the type's Poster.
 * Currencies may be given as ids (`currency_id`) or codes (`currency`).
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $money = ['nullable', 'numeric', 'min:0', 'max:999999999999999'];

        return [
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(Voucher::TYPES)],
            'version' => [$creating ? 'prohibited' : 'required', 'integer'],
            'purpose' => ['nullable', Rule::in(Voucher::PURPOSES)],
            'voucher_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:sys_branches,id'],
            'cash_box_id' => ['nullable', 'integer', 'exists:fin_cash_boxes,id'],
            'counter_cash_box_id' => ['nullable', 'integer', 'exists:fin_cash_boxes,id'],
            'currency_id' => ['nullable', 'integer', 'exists:sys_currencies,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'counter_currency_id' => ['nullable', 'integer', 'exists:sys_currencies,id'],
            'counter_currency' => ['nullable', 'string', 'size:3'],
            'fee_currency_id' => ['nullable', 'integer', 'exists:sys_currencies,id'],
            'fee_currency' => ['nullable', 'string', 'size:3'],
            'amount' => $money,
            'counter_amount' => $money,
            'fee_amount' => $money,
            'advance_deduction' => $money,
            'rate' => ['nullable', 'numeric', 'gt:0'],
            'party_id' => ['nullable', 'integer', 'exists:fin_parties,id'],
            'project_id' => ['nullable', 'integer', 'exists:prj_projects,id'],
            'project_cost_category_id' => ['nullable', 'integer', 'exists:fin_project_cost_categories,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:fin_expense_categories,id'],
            'asset_id' => ['nullable', 'integer', 'exists:fin_assets,id'],
            'employee_id' => ['nullable', 'integer'],
            'contractor_agreement_id' => ['nullable', 'integer', 'exists:fin_contractor_agreements,id'],
            'custody_id' => ['nullable', 'integer', 'exists:fin_custodies,id'],
            'account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'classification' => ['nullable', Rule::in(['partner_personal', 'company_expense'])],
            'debt_direction' => ['nullable', Rule::in(['we_owe', 'owed_to_us'])],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'meta' => ['nullable', 'array'],
            'approve' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'lines.*.cash_box_id' => ['nullable', 'integer', 'exists:fin_cash_boxes,id'],
            'lines.*.expense_category_id' => ['nullable', 'integer', 'exists:fin_expense_categories,id'],
            'lines.*.project_id' => ['nullable', 'integer', 'exists:prj_projects,id'],
            'lines.*.project_cost_category_id' => ['nullable', 'integer', 'exists:fin_project_cost_categories,id'],
            'lines.*.party_id' => ['nullable', 'integer', 'exists:fin_parties,id'],
            'lines.*.asset_id' => ['nullable', 'integer', 'exists:fin_assets,id'],
            'lines.*.employee_id' => ['nullable', 'integer'],
            'lines.*.currency_id' => ['nullable', 'integer', 'exists:sys_currencies,id'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.debit' => $money,
            'lines.*.credit' => $money,
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
