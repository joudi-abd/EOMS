<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\RehireEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeRehireService;
use App\Support\ApiResponse;
use Illuminate\Routing\Controller;

class EmployeeRehireController extends Controller
{
    public function store(RehireEmployeeRequest $request, Employee $employee, EmployeeRehireService $service)
    {
        return ApiResponse::ok(new EmployeeResource($service->rehire($employee, $request->validated(), $request->user())));
    }
}
