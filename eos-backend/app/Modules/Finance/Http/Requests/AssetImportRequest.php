<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** CSV of existing assets (spec §12 step 7, OP-15), entered as opening balances of one branch on one date. */
class AssetImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'date' => ['required', 'date_format:Y-m-d'],
            'branch_id' => ['required', 'integer', 'exists:sys_branches,id'],
        ];
    }
}
