<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\StoreEmployeeAccountRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccountLinkService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Controller;

#[Group('05 - HR / Employees', weight: 5)]
class EmployeeAccountController extends Controller
{
    public function __construct(private EmployeeAccountLinkService $accounts) {}

    /** Create an optional login account linked to an existing employee. */
    public function store(StoreEmployeeAccountRequest $request, Employee $employee)
    {
        return ApiResponse::created(new EmployeeResource(
            $this->accounts->create($employee, $request->validated())
        ));
    }
}
