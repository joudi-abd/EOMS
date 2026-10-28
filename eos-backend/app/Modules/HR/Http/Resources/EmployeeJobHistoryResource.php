<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeJobHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'event_type' => $this->event_type,
            'reason' => $this->reason,
            'old_department_id' => $this->old_department_id,
            'new_department_id' => $this->new_department_id,
            'old_job_title_id' => $this->old_job_title_id,
            'new_job_title_id' => $this->new_job_title_id,
            'old_manager_id' => $this->old_manager_id,
            'new_manager_id' => $this->new_manager_id,
            'effective_date' => $this->effective_date?->format('Y-m-d'),
        ];
    }
}
