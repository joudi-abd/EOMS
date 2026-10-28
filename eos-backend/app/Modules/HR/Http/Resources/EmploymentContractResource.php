<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmploymentContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = $this->status === 'active' && $this->end_date !== null
            && $this->end_date->startOfDay()->lt(today());

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'contract_number' => $this->contract_number,
            'type' => $this->type,
            'status' => $expired ? 'expired' : $this->status,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'terminated_at' => $this->terminated_at?->format('Y-m-d'),
            'scheduled_termination_at' => $this->scheduled_termination_at?->format('Y-m-d'),
            'scheduled_termination_reason' => $this->scheduled_termination_reason,
            'renewed_from_id' => $this->renewed_from_id,
            'base_salary' => $this->when($request->user()?->can('employees.view_financial'), fn () => $this->base_salary),
            'currency_code' => $this->when($request->user()?->can('employees.view_financial'), fn () => $this->currency_code),
        ];
    }
}
