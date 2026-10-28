<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('05 - HR / Employees', weight: 5)]
class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    /**
     * List employees.
     *
     * Returns a paginated employee list.
     * Supports searching and filtering by department,
     * job title, and employment status.
     */
    public function index(Request $request)
    {
        return ApiResponse::paginated(
            $this->employees->search($request->query())
                ->through(fn (Employee $employee) => new EmployeeResource($employee))
        );
    }

    /**
     * Show employee details.
     *
     * Returns the employee profile with related
     * department, job title, and manager information.
     */
    public function show(Employee $employee)
    {
        return ApiResponse::ok(new EmployeeResource(
            $employee->load(['department', 'jobTitle', 'manager:id,employee_no,first_name,last_name'])
        ));
    }

    /**
     * Create an employee.
     *
     * Creates an employee record and an associated
     * user account with the employee role.
     *
     * Requires employees.manage permission.
     */
    public function store(StoreEmployeeRequest $request)
    {
        return ApiResponse::created(new EmployeeResource(
            $this->employees->create($request->validated())
        ));
    }

    /**
     * Update employee information.
     *
     * Updates the employee profile and synchronizes
     * supported identity fields with the login account.
     *
     * Prevents circular manager relationships.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        return ApiResponse::ok(new EmployeeResource(
            $this->employees->update($employee, $request->validated())
        ));
    }
}
