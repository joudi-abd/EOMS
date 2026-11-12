<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\HrScope;
use Illuminate\Http\Request;

class AttendanceAccess
{
    public function __construct(private HrScope $scope) {}

    public function employee(Request $request, Employee $employee): void
    {
        // attendance.view at company scope sees everyone; at department scope, the departments the user manages.
        abort_unless((int) $employee->user_id === (int) $request->user()->id
            || $this->scope->canSee($request->user(), $employee, 'attendance'), 403);
    }
}
