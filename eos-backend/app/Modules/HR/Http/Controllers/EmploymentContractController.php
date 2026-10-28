<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\ScheduleEmploymentContractTerminationRequest;
use App\Modules\HR\Http\Requests\StoreEmploymentContractRequest;
use App\Modules\HR\Http\Requests\TerminateEmploymentContractRequest;
use App\Modules\HR\Http\Resources\EmploymentContractResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\EmploymentContractService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Controller;

#[Group('06 - HR / Contracts', weight: 6)]
class EmploymentContractController extends Controller
{
    public function __construct(private EmploymentContractService $contracts) {}

    /** List all employment contracts for an employee. */
    public function index(Employee $employee)
    {
        return ApiResponse::paginated(
            $this->contracts->paginate($employee)
                ->through(fn (EmploymentContract $contract) => new EmploymentContractResource($contract))
        );
    }

    /** View an employment contract. */
    public function show(Employee $employee, EmploymentContract $contract)
    {
        return ApiResponse::ok(new EmploymentContractResource($this->contracts->find($employee, $contract)));
    }

    /** Create a draft contract. */
    public function store(StoreEmploymentContractRequest $request, Employee $employee)
    {
        return ApiResponse::created(new EmploymentContractResource(
            $this->contracts->create($employee, $request->validated())
        ));
    }

    /** Renew a contract by creating a new draft and preserving the original. */
    public function renew(StoreEmploymentContractRequest $request, Employee $employee, EmploymentContract $contract)
    {
        return ApiResponse::created(new EmploymentContractResource(
            $this->contracts->create($employee, $request->validated(), $contract)
        ));
    }

    /** Activate a draft contract after checking for overlapping periods. */
    public function activate(Employee $employee, EmploymentContract $contract)
    {
        return ApiResponse::ok(new EmploymentContractResource($this->contracts->activate($employee, $contract)));
    }

    /** Schedule termination for a future date; the contract remains active until then. */
    public function scheduleTermination(
        ScheduleEmploymentContractTerminationRequest $request,
        Employee $employee,
        EmploymentContract $contract
    ) {
        return ApiResponse::ok(new EmploymentContractResource(
            $this->contracts->scheduleTermination($employee, $contract, $request->validated())
        ));
    }

    /** Cancel a pending scheduled termination before it becomes due. */
    public function cancelScheduledTermination(Employee $employee, EmploymentContract $contract)
    {
        return ApiResponse::ok(new EmploymentContractResource(
            $this->contracts->cancelScheduledTermination($employee, $contract)
        ));
    }

    /** Terminate an active contract. Does not terminate the employee account. */
    public function terminate(TerminateEmploymentContractRequest $request, Employee $employee, EmploymentContract $contract)
    {
        return ApiResponse::ok(new EmploymentContractResource(
            $this->contracts->terminate($employee, $contract, $request->validated())
        ));
    }
}
