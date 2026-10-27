<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    public function index(Request $request)
    {
        return ApiResponse::paginated(
            $this->employees->search($request->query())
                ->through(fn (Employee $employee) => new EmployeeResource($employee))
        );
    }

    public function show(Employee $employee)
    {
        return ApiResponse::ok(new EmployeeResource(
            $employee->load(['department', 'jobTitle', 'manager:id,employee_no,first_name,last_name'])
        ));
    }

    public function store(StoreEmployeeRequest $request)
    {
        return ApiResponse::created(new EmployeeResource(
            $this->employees->create($request->validated())
        ));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        return ApiResponse::ok(new EmployeeResource(
            $this->employees->update($employee, $request->validated())
        ));
    }
}
