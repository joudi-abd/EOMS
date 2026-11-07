<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\StoreLeaveBalanceEntryRequest;
use App\Modules\HR\Http\Requests\StoreLeavePolicyRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\LeaveSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveSettingsController
{
    public function __construct(private LeaveSettingsService $settings) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->settings->settings());
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:hr_leave_types,name']]);

        return ApiResponse::created($this->settings->addType($data['name']));
    }

    public function publishPolicy(StoreLeavePolicyRequest $request, LeaveType $leaveType): JsonResponse
    {
        return ApiResponse::created($this->settings->publish($leaveType, $request->validated()));
    }

    public function updateActivation(Request $request, LeaveType $leaveType): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        return ApiResponse::ok($this->settings->activate($leaveType, (bool) $data['active']));
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d', 'unique:hr_office_holidays,date'],
        ]);

        return ApiResponse::created($this->settings->addHoliday($data));
    }

    public function destroyHoliday(OfficeHoliday $holiday): JsonResponse
    {
        $this->settings->removeHoliday($holiday);

        return ApiResponse::ok(null);
    }

    public function balanceEntries(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:hr_employees,id']]);

        return ApiResponse::paginated($this->settings->balanceEntries((int) $data['employee_id']));
    }

    public function storeBalanceEntry(StoreLeaveBalanceEntryRequest $request): JsonResponse
    {
        return ApiResponse::created($this->settings->recordBalance($request->validated(), (int) $request->user()->getKey()));
    }
}
