<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Services\EmploymentPeriodService;
use Illuminate\Foundation\Http\FormRequest;

class RehireEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.app(EmploymentPeriodService::class)->today()],
            'reason' => ['required', 'string', 'min:5', 'max:500', 'regex:/\S/u'],
            'confirm_new_service_period' => ['required', 'accepted'],
        ];
    }
}
