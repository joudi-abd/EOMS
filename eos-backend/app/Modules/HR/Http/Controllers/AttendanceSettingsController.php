<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Services\Attendance\AttendanceDeviceService;
use App\Modules\Identity\Services\AuditLogger;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceSettingsController extends Controller
{
    public function policies(): JsonResponse
    {
        return ApiResponse::ok(AttendancePolicy::query()->orderByDesc('effective_from')->get());
    }

    public function storePolicy(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d', 'unique:hr_attendance_policies,effective_from'],
            'timezone' => ['required', 'timezone'],
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_minutes' => ['required', 'integer', 'between:0,180'],
            'grace_mode' => ['required', Rule::in(['threshold_full', 'excess_only', 'completed_blocks'])],
            'late_block_minutes' => ['sometimes', 'required', 'integer', 'between:1,180'],
            'overtime_multiplier' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:1,10'],
            'pairing_mode' => ['required', Rule::in(['first_last', 'directional'])],
            'duplicate_seconds' => ['required', 'integer', 'between:0,120'],
            'finalize_after_minutes' => ['required', 'integer', 'between:0,1440'],
        ]);
        $data += ['late_block_minutes' => 60, 'overtime_multiplier' => '2.00'];
        $data['working_days'] = array_map('intval', $data['working_days']);
        $policy = DB::transaction(function () use ($data, $audit) {
            $first = AttendancePolicy::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            if ($data['timezone'] !== $first->timezone
                || $data['effective_from'] <= CarbonImmutable::now($first->timezone)->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => ['أنشئ نسخة تبدأ من الغد أو لاحقاً؛ المنطقة الزمنية للمكتب ثابتة في النسخة الأولى.']]);
            }
            if (AttendancePolicy::query()->where('effective_from', $data['effective_from'])->exists()) {
                throw ValidationException::withMessages(['effective_from' => ['توجد سياسة بنفس تاريخ السريان.']]);
            }
            $policy = AttendancePolicy::create($data);
            $audit->log('attendance.policy_created', $policy, null, $data);

            return $policy;
        }, 3);

        return ApiResponse::created($policy);
    }

    public function devices(): JsonResponse
    {
        return ApiResponse::ok(AttendanceDevice::query()->orderBy('id')->get());
    }

    public function storeDevice(Request $request, AttendanceDeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'model' => ['sometimes', 'required', 'string', 'max:64'],
            'serial_number' => ['required', 'string', 'max:100', 'unique:hr_attendance_devices,serial_number'],
        ]);
        $data['timezone'] = AttendancePolicy::query()->orderBy('effective_from')->value('timezone');

        return ApiResponse::created($devices->create($data));
    }

    public function updateDevice(Request $request, AttendanceDevice $device, AttendanceDeviceService $devices): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return ApiResponse::ok($devices->setActive($device, (bool) $data['is_active']));
    }

    public function rotateToken(AttendanceDevice $device, AttendanceDeviceService $devices): JsonResponse
    {
        return ApiResponse::ok($devices->rotate($device));
    }

    public function bindings(AttendanceDevice $device): JsonResponse
    {
        return ApiResponse::paginated(AttendanceBinding::query()->where('device_id', $device->id)->orderByDesc('id')->paginate(50));
    }

    public function storeBinding(Request $request, AttendanceDevice $device, AttendanceDeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'device_user_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);

        return ApiResponse::created($devices->bind($device, $data));
    }

    public function closeBinding(Request $request, AttendanceBinding $binding, AttendanceDeviceService $devices): JsonResponse
    {
        $data = $request->validate(['valid_to' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::ok($devices->closeBinding($binding, $data['valid_to']));
    }
}
