<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\OfficeHoliday;

/**
 * Read-only view of the leave calendar as attendance needs it.
 * Only approved, still-active leave days change attendance; pending requests are informational.
 */
class AttendanceLeaveContext
{
    public function approvedDay(int $employeeId, string $date): ?LeaveRequestDay
    {
        // One request per employee per day is enforced by the leave workflow.
        return LeaveRequestDay::query()->where('employee_id', $employeeId)->where('date', $date)->where('active', true)
            ->whereIn('leave_request_id', LeaveRequest::query()->where('status', 'approved')->select('id'))
            ->orderBy('id')->first();
    }

    public function pendingRequestId(int $employeeId, string $date): ?int
    {
        $id = LeaveRequestDay::query()->where('employee_id', $employeeId)->where('date', $date)->where('active', true)
            ->whereIn('leave_request_id', LeaveRequest::query()->where('status', 'pending')->select('id'))
            ->value('leave_request_id');

        return $id === null ? null : (int) $id;
    }

    public function holiday(string $date): ?OfficeHoliday
    {
        return OfficeHoliday::query()->whereDate('date', $date)->first();
    }
}
