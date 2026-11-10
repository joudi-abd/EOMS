<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceImportService
{
    public function __construct(
        private AuditLogger $audit,
        private AttendanceCalculator $calculator,
    ) {}

    public function import(AttendanceDevice $device, array $events, ?string $completeThrough): array
    {
        // Validate and parse the entire batch before changing any stored event.
        $parsed = array_map(fn ($event) => array_merge($event, ['utc' => AttendanceClock::utc($event['occurred_at'])]), $events);
        $marker = $completeThrough === null ? null : $this->parseMarker($completeThrough);

        return DB::transaction(function () use ($device, $parsed, $marker) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->getKey());
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
                    ->where('device_user_id', $event['device_user_id'])->validOn($date)->first();
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

    /**
     * Explicit administrative attestation that every punch up to $completeThrough has been received.
     * ADMS heartbeats and ATTLOG batches never call this: the protocol does not prove full sync.
     */
    public function confirmSync(AttendanceDevice $device, string $completeThrough, string $note, int $actorId): AttendanceDevice
    {
        $marker = $this->parseMarker($completeThrough);
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => ['أدخل ملاحظة توضح أساس تأكيد المزامنة (5 أحرف على الأقل).']]);
        }

        $previous = null;
        $device = DB::transaction(function () use ($device, $marker, $note, $actorId, &$previous) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->getKey());
            abort_unless($device->is_active, 403, 'الجهاز معطل.');
            $previous = $device->complete_through;
            if ($previous !== null && $marker->lessThan($previous)) {
                throw ValidationException::withMessages([
                    'complete_through' => ['لا يمكن إرجاع تأكيد المزامنة إلى وقت أقدم من آخر تأكيد.'],
                ]);
            }
            if ($previous === null || $marker->greaterThan($previous)) {
                $device->update(['complete_through' => $marker]);
            }
            $this->audit->log(
                'attendance.device_sync_confirmed',
                $device,
                ['complete_through' => $previous?->toIso8601String()],
                [
                    'complete_through' => $device->refresh()->complete_through?->toIso8601String(),
                    'note' => $note,
                    'source' => 'manual_attestation',
                ],
                $actorId,
            );

            return $device->refresh();
        }, 3);

        $this->refreshCoverageAfterSync($device, $previous, $marker);

        return $device;
    }

    private function parseMarker(string $completeThrough): CarbonImmutable
    {
        $marker = AttendanceClock::utc($completeThrough);
        if ($marker->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['complete_through' => ['لا يمكن تأكيد مزامنة وقت في المستقبل.']]);
        }

        return $marker;
    }

    private function refreshCoverageAfterSync(
        AttendanceDevice $device,
        ?CarbonImmutable $previous,
        CarbonImmutable $marker,
    ): void {
        $zone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone') ?? 'UTC';
        $to = $marker->setTimezone($zone)->startOfDay();
        $from = $previous !== null
            ? $previous->setTimezone($zone)->startOfDay()
            : $to->subDays(7);
        if ($from->greaterThan($to)) {
            $from = $to;
        }

        $employeeIds = AttendanceBinding::query()
            ->where('device_id', $device->id)
            ->pluck('employee_id')
            ->unique()
            ->filter();

        foreach ($employeeIds as $employeeId) {
            $employee = Employee::query()->find($employeeId);
            if ($employee === null) {
                continue;
            }
            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                try {
                    $this->calculator->calculate($employee, $date->toDateString());
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
