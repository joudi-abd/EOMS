<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSensitive = (bool) $request->user()?->can('employees.view_sensitive');
        $canFinancial = (bool) $request->user()?->can('employees.view_financial');

        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'has_account' => $this->user_id !== null,
            'first_name' => $this->first_name,
            'father_name' => $this->father_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'department_id' => $this->department_id,
            'job_title_id' => $this->job_title_id,
            'manager_id' => $this->manager_id,
            'status' => $this->status,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'department' => $this->whenLoaded('department'),
            'job_title' => $this->whenLoaded('jobTitle'),
            'manager' => $this->whenLoaded('manager'),
            'birth_date' => $this->when($canSensitive, fn () => $this->birth_date?->format('Y-m-d')),
            'email' => $this->when($canSensitive, fn () => $this->email),
            'phone' => $this->when($canSensitive, fn () => $this->phone),
            'address' => $this->when($canSensitive, fn () => $this->address),
            'termination_date' => $this->when($canSensitive, fn () => $this->termination_date?->format('Y-m-d')),
            'termination_reason' => $this->when($canSensitive, fn () => $this->termination_reason),
            'biometric_code' => $this->when($canSensitive, fn () => $this->biometric_code),
            'user_id' => $this->when($canSensitive, fn () => $this->user_id),
            'sham_cash_wallet' => $this->when($canFinancial, fn () => $this->sham_cash_wallet),
        ];
    }
}
