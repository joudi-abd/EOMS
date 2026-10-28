<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleEmploymentContractTerminationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'terminated_at' => ['required', 'date_format:Y-m-d', 'after:today'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
