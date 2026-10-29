<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;

class AttendanceAccess
{
    public function employee(Request $request, Employee $employee): void
    {
        // attendance.view is an explicit organisation-wide HR/reporting permission.
        abort_unless($request->user()->can('attendance.view')
            || (int) $employee->user_id === (int) $request->user()->id, 403);
    }
}
