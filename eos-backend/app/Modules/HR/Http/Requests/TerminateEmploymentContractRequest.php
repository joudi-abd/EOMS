<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TerminateEmploymentContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'terminated_at' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
