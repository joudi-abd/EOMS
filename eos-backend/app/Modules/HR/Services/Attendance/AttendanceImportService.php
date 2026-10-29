<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceImportService
{
    public function import(AttendanceDevice $device, array $events, ?string $completeThrough): array
    {
        // Validate and parse the entire batch before changing any stored event.
        $parsed = array_map(fn ($event) => array_merge($event, ['utc' => AttendanceClock::utc($event['occurred_at'])]), $events);
        $marker = $completeThrough === null ? null : AttendanceClock::utc($completeThrough);
        if ($marker?->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['complete_through' => ['لا يمكن تأكيد مزامنة وقت في المستقبل.']]);
        }

        return DB::transaction(function () use ($device, $parsed, $marker) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_unless($device->is_active, 403, 'الجهاز معطل.');
            $officeZone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone');
            $inserted = $duplicates = $unmatched = 0;
            foreach ($parsed as $event) {
                $at = $event['utc'];
                $direction = $event['direction'] ?? 'unknown';
                $existing = AttendancePunch::query()->where('device_id', $device->id)
                    ->where('external_id', $event['external_id'])->first();
                if ($existing) {
                    if ($existing->device_user_id !== $event['device_user_id']
                        || $existing->occurred_at->timestamp !== $at->timestamp || $existing->direction !== $direction) {
                        throw ValidationException::withMessages(['events' => ['معرّف حدث مكرر ببيانات مختلفة؛ أُلغيت الدفعة كاملة.']]);
                    }
                    $duplicates++;

                    continue;
                }
                $date = $at->setTimezone($officeZone)->toDateString();
                $binding = AttendanceBinding::query()->where('device_id', $device->id)
                    ->where('device_user_id', $event['device_user_id'])->where('valid_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))->first();
                AttendancePunch::create([
                    'device_id' => $device->id, 'employee_id' => $binding?->employee_id,
                    'external_id' => $event['external_id'], 'device_user_id' => $event['device_user_id'],
                    'occurred_at' => $at, 'direction' => $direction, 'received_at' => CarbonImmutable::now('UTC'),
                ]);
                if ($binding) {
                    AttendanceDay::query()->where('employee_id', $binding->employee_id)
                        ->where('work_date', $date)->whereNotNull('approved_at')->update(['pending_changes' => true]);
                } else {
                    $unmatched++;
                }
                $inserted++;
            }
            $update = ['last_seen_at' => CarbonImmutable::now('UTC')];
            if ($marker && ($device->complete_through === null || $marker->greaterThan($device->complete_through))) {
                $update['complete_through'] = $marker;
            }
            $device->update($update);

            return ['inserted' => $inserted, 'duplicates' => $duplicates, 'unmatched' => $unmatched,
                'complete_through' => $device->refresh()->complete_through?->toIso8601String()];
        }, 3);
    }
}
