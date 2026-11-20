<?php

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Services\Core\AttachmentService;
use App\Modules\Finance\Services\Expenses\SiteRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Site expense request (R24), sent as multipart/form-data with the invoice image in `invoice` (mandatory). */
class StoreSiteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:prj_projects,id'],
            'project_cost_category_id' => ['required', 'integer', 'exists:fin_project_cost_categories,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999'],
            'currency_id' => ['required_without:currency', 'nullable', 'integer', 'exists:sys_currencies,id'],
            'currency' => ['required_without:currency_id', 'nullable', 'string', 'size:3'],
            'supplier_party_id' => ['nullable', 'integer', 'exists:fin_parties,id'],
            'description' => ['required', 'string', 'max:500'],
            'payment_source' => ['required', Rule::in(SiteRequestService::SOURCES)],
            'invoice' => ['required', 'file', 'mimes:'.implode(',', AttachmentService::MIMES), 'max:'.AttachmentService::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return ['invoice.required' => 'صورة الفاتورة إلزامية.'];
    }
}
