<?php

namespace App\Modules\HR\Http\Resources;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\Attendance\AttendanceLeaveContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = Employee::findOrFail($this->employee_id);
        $policy = AttendancePolicy::findOrFail($this->policy_id);
        $date = $this->work_date->format('Y-m-d');
        $leaveDay = $this->leave_request_day_id ? LeaveRequestDay::find($this->leave_request_day_id) : null;
        $leaveRequest = $leaveDay ? LeaveRequest::find($leaveDay->leave_request_id) : null;
        $holiday = $this->status === 'holiday' ? OfficeHoliday::query()->whereDate('date', $date)->first() : null;

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
            'worked_minutes' => $this->worked_minutes, 'required_minutes' => $this->required_minutes,
            'leave_minutes' => $this->leave_minutes,
            // Snapshot the day was calculated with; pay split stays informational until payroll exists.
            'leave' => $leaveDay ? [
                'request_id' => $leaveDay->leave_request_id, 'request_day_id' => $leaveDay->id,
                'leave_type' => ['id' => $leaveRequest?->leave_type_id, 'name' => $leaveRequest ? LeaveType::find($leaveRequest->leave_type_id)?->name : null],
                'start_time' => $leaveDay->start_time, 'end_time' => $leaveDay->end_time,
                'full_day' => $this->status === 'on_leave', 'pay_percent' => $leaveDay->pay_percent,
                'day_units' => $leaveDay->day_units, 'covered_units' => $leaveDay->covered_units, 'unpaid_units' => $leaveDay->unpaid_units,
                'still_active' => $leaveDay->active && $leaveRequest?->status === 'approved',
            ] : null,
            'pending_leave_request_id' => app(AttendanceLeaveContext::class)->pendingRequestId($this->employee_id, $date),
            'holiday' => $holiday ? ['id' => $holiday->id, 'name' => $holiday->name] : null,
            'late_actual_minutes' => $this->late_actual_minutes,
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
