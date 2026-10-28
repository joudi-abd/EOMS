<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\TerminateEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Controller;

#[Group('05 - HR / Employees', weight: 5)]
class EmployeeTerminationController extends Controller
{
    public function __construct(private EmployeeTerminationService $terminations) {}

    /** Terminate employment after settling active contracts and disable linked login. */
    public function store(TerminateEmployeeRequest $request, Employee $employee)
    {
        return ApiResponse::ok(new EmployeeResource(
            $this->terminations->terminate($employee, $request->validated(), $request->user())
        ));
    }
}
