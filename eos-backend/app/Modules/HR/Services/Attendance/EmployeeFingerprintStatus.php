<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;

class EmployeeFingerprintStatus
{
    public function summarize(Employee $employee, bool $details = true): array
    {
        $employee->loadMissing(['attendanceBindings.device', 'attendanceBindings.latestEnrollment']);
        $bindings = $employee->attendanceBindings->filter(function ($binding) {
            $device = $binding->device;
            if (! $device?->is_active) {
                return false;
            }
            $today = CarbonImmutable::now($device->timezone)->toDateString();

            return $binding->isValidOn($today);
        })->map(function ($binding) {
            $enrollment = $binding->latestEnrollment;
            $state = $enrollment?->status;
            if ($enrollment && in_array($state, ['queued_user', 'await_user_ack', 'queued_enroll', 'await_enroll_ack'], true)
                && CarbonImmutable::parse($enrollment->expires_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $state = 'timeout';
            }
            $status = match ($state) {
                'manually_confirmed' => 'enrolled',
                'awaiting_physical_verification' => 'waiting',
                'queued_user', 'await_user_ack', 'queued_enroll', 'await_enroll_ack' => 'queued',
                'failed', 'timeout' => 'failed',
                default => 'linked',
            };

            return ['binding_id' => $binding->id, 'device_id' => $binding->device_id,
                'device_name' => $binding->device->name, 'device_user_id' => $binding->device_user_id,
                'status' => $status, 'enrollment_id' => $enrollment?->id,
                'confirmed_at' => $enrollment?->status === 'manually_confirmed' ? $enrollment->confirmed_at : null];
        })->values();
        // A successful binding is shown first; every device remains visible in the details.
        $priority = ['enrolled', 'waiting', 'queued', 'failed', 'linked'];
        $primary = $bindings->sortBy(fn ($row) => array_search($row['status'], $priority, true))->first();

        return ['status' => $primary['status'] ?? 'none',
            'device_user_id' => $details ? ($primary['device_user_id'] ?? null) : null, 'bindings' => $details ? $bindings->all() : []];
    }
}
