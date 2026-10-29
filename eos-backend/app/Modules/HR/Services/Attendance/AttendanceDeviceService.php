<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceDeviceService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $token = Str::random(64);
            $device = AttendanceDevice::create($data + ['token_hash' => hash('sha256', $token)]);
            $this->audit->log('attendance.device_created', $device, null, ['name' => $device->name]);

            return ['device' => $device->refresh(), 'token' => $token];
        });
    }

    public function rotate(AttendanceDevice $device): array
    {
        return DB::transaction(function () use ($device) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
            $token = Str::random(64);
            $device->update(['token_hash' => hash('sha256', $token)]);
            $this->audit->log('attendance.device_token_rotated', $device);

            return ['device' => $device->refresh(), 'token' => $token];
        });
    }

    public function setActive(AttendanceDevice $device, bool $active): AttendanceDevice
    {
        return DB::transaction(function () use ($device, $active) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
            $before = $device->is_active;
            $device->update(['is_active' => $active]);
            $this->audit->log('attendance.device_status_changed', $device, ['is_active' => $before], ['is_active' => $active]);

            return $device;
        });
    }

    public function bind(AttendanceDevice $device, array $data): AttendanceBinding
    {
        return DB::transaction(function () use ($device, $data) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
            $overlap = AttendanceBinding::query()->where('device_id', $device->id)
                ->where(fn ($q) => $q->where('device_user_id', $data['device_user_id'])
                    ->orWhere('employee_id', $data['employee_id']))
                ->where('valid_from', '<=', $data['valid_to'] ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from']))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['device_user_id' => ['يتداخل الربط مع رقم أو موظف مرتبط بالجهاز في نفس الفترة.']]);
            }
            $binding = AttendanceBinding::create($data + ['device_id' => $device->id]);
            // Link previously unknown events only; never reassign existing historical owners.
            $officeZone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone');
            AttendancePunch::query()->where('device_id', $device->id)
                ->where('device_user_id', $binding->device_user_id)->whereNull('employee_id')
                ->orderBy('id')->chunkById(100, function ($punches) use ($binding, $officeZone) {
                    foreach ($punches as $punch) {
                        $date = $punch->occurred_at->setTimezone($officeZone)->toDateString();
                        if ($date >= $binding->valid_from->format('Y-m-d')
                            && ($binding->valid_to === null || $date <= $binding->valid_to->format('Y-m-d'))) {
                            $punch->update(['employee_id' => $binding->employee_id]);
                        }
                    }
                });
            $this->audit->log('attendance.device_binding_created', $binding, null, $data);

            return $binding;
        }, 3);
    }

    public function closeBinding(AttendanceBinding $binding, string $date): AttendanceBinding
    {
        return DB::transaction(function () use ($binding, $date) {
            AttendanceDevice::query()->lockForUpdate()->findOrFail($binding->device_id);
            $binding = AttendanceBinding::query()->lockForUpdate()->findOrFail($binding->id);
            $zone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone');
            if ($date < CarbonImmutable::now($zone)->toDateString()
                || $date < $binding->valid_from->format('Y-m-d') || $binding->valid_to !== null) {
                throw ValidationException::withMessages(['valid_to' => ['يمكن إغلاق ربط مفتوح من اليوم أو بتاريخ لاحق فقط.']]);
            }
            $binding->update(['valid_to' => $date]);
            $this->audit->log('attendance.device_binding_closed', $binding, null, ['valid_to' => $date]);

            return $binding;
        });
    }
}
