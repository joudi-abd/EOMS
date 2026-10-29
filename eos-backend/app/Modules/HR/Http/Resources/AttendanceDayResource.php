<?php

namespace App\Modules\HR\Http\Resources;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = Employee::findOrFail($this->employee_id);
        $policy = AttendancePolicy::findOrFail($this->policy_id);

        return [
            'id' => $this->id,
            'employee' => ['id' => $employee->id, 'employee_no' => $employee->employee_no,
                'name' => $employee->full_name, 'department_id' => $employee->department_id],
            'work_date' => $this->work_date->format('Y-m-d'),
            'policy' => ['id' => $policy->id, 'timezone' => $policy->timezone,
                'start_time' => $policy->start_time, 'end_time' => $policy->end_time,
                'grace_minutes' => $policy->grace_minutes, 'grace_mode' => $policy->grace_mode,
                'late_block_minutes' => $policy->late_block_minutes, 'overtime_multiplier' => $policy->overtime_multiplier,
                'pairing_mode' => $policy->pairing_mode],
            'status' => $this->status, 'first_in' => $this->first_in?->toIso8601String(),
            'last_out' => $this->last_out?->toIso8601String(),
            'worked_minutes' => $this->worked_minutes, 'late_actual_minutes' => $this->late_actual_minutes,
            'late_minutes' => $this->late_minutes,
            'late_hours' => $this->late_minutes === null ? null : $this->late_minutes / 60, 'early_minutes' => $this->early_minutes,
            'extra_minutes_unapproved' => $this->extra_minutes, 'flags' => $this->flags,
            'coverage_complete' => $this->coverage_complete, 'pending_changes' => $this->pending_changes,
            'version' => $this->version, 'approved_at' => $this->approved_at?->toIso8601String(),
            'review_status' => $this->approved_at ? 'approved' : 'draft',
            'permissions' => [
                'can_request_correction' => $this->approved_at === null && ($request->user()?->can('attendance.adjust')
                    || (int) $employee->user_id === (int) $request->user()?->id),
                'can_approve' => $request->user()?->can('attendance.approve') && $this->approved_at === null,
                'can_reopen' => $request->user()?->can('attendance.approve') && $this->approved_at !== null,
            ],
        ];
    }
}
