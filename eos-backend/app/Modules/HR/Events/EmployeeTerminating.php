<?php

namespace App\Modules\HR\Events;

use App\Modules\HR\Models\Employee;

/**
 * Fired inside the termination transaction before service is closed.
 * Other modules may abort with ValidationException (e.g. open payroll disbursements).
 */
class EmployeeTerminating
{
    public function __construct(public Employee $employee) {}
}
