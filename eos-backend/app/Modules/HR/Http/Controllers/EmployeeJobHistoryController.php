<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Resources\EmployeeJobHistoryResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeJobHistoryService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Controller;

#[Group('05 - HR / Employees', weight: 5)]
class EmployeeJobHistoryController extends Controller
{
    public function __construct(private EmployeeJobHistoryService $histories) {}

    /**
     * List employee job history.
     *
     * Returns the initial assignment and subsequent department,
     * job title, and reporting-manager changes. Requires employees.view.
     */
    public function index(Employee $employee)
    {
        return ApiResponse::paginated(
            $this->histories->paginate($employee)
                ->through(fn ($item) => new EmployeeJobHistoryResource($item))
        );
    }
}
