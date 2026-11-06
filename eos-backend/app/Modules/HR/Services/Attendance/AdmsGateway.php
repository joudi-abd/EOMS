<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmsGateway
{
    public function __construct(private AttendanceImportService $importer, private AuditLogger $audit) {}

    public function options(AttendanceDevice $device): string
    {
        $this->touch($device);

        // PUSH interprets Date (GMT) using this offset; omission can retain the device's old zone.
        $offset = CarbonImmutable::now($device->timezone)->getOffset();
        $timeZone = $offset % 3600 === 0 && abs($offset) < 12 * 3600
            ? intdiv($offset, 3600) : intdiv($offset, 60);

        // Start with history requested, never the laboratory probe's high-water 9999.
        return "GET OPTION FROM: {$device->serial_number}\nATTLOGStamp=0\nOPERLOGStamp=0\nATTPHOTOStamp=0\n"
            ."ErrorDelay=30\nDelay=10\nTransTimes=00:00;14:05\nTransInterval=1\n"
            ."TransFlag=AttLog\nTimeZone={$timeZone}\nRealtime=1\nEncrypt=0\nServerVer=2.4.1\n";
    }

    public function ingest(AttendanceDevice $device, string $raw): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $raw), fn ($line) => trim($line) !== ''));
        if (count($lines) > config('attendance.adms.max_records')) {
            throw ValidationException::withMessages(['body' => ['Too many attendance records.']]);
        }
        $events = [];
        foreach ($lines as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 4 || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $fields[0])
                || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fields[1])
                || ! ctype_digit($fields[2]) || ! ctype_digit($fields[3])) {
                throw ValidationException::withMessages(['body' => ['Invalid ATTLOG record.']]);
            }
            try {
                $local = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $fields[1], $device->timezone);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['body' => ['Invalid ATTLOG timestamp.']]);
            }
            if (! $local || $local->format('Y-m-d H:i:s') !== $fields[1] || $local->year < 1970) {
                throw ValidationException::withMessages(['body' => ['Invalid ATTLOG timestamp.']]);
            }
            // Normalize line endings/trailing tabs, preserving all meaningful device fields.
            $identity = rtrim($line, "\t ");
            $events[] = ['external_id' => 'adms:'.hash('sha256', $identity),
                'device_user_id' => $fields[0], 'occurred_at' => $local->toIso8601String(),
                'direction' => match ($fields[2]) {
                    '0' => 'in', '1' => 'out', default => 'unknown'
                }];
        }

        return DB::transaction(function () use ($device, $events, $raw, $lines) {
            $device = $this->lock($device);
            $hash = hash('sha256', $raw);
            $upload = DB::table('hr_adms_uploads')->where('device_id', $device->id)->where('body_hash', $hash)->first();
            $uploadId = $upload?->id ?? DB::table('hr_adms_uploads')->insertGetId([
                'device_id' => $device->id, 'body_hash' => $hash, 'raw_body' => $raw,
                'record_count' => count($lines), 'received_at' => $this->now(),
            ]);
            $valid = [];
            $quarantined = $known = 0;
            $cutoff = CarbonImmutable::now('UTC')->addMinutes(5);
            foreach ($events as $event) {
                // Quarantine is sticky, including after time catches up or a manual review.
                $held = DB::table('hr_adms_quarantined_punches')->where('device_id', $device->id)
                    ->where('external_id', $event['external_id'])->first();
                if ($held) {
                    if ($held->status === 'pending') {
                        $quarantined++;
                    } else {
                        $known++;
                    }

                    continue;
                }
                $at = CarbonImmutable::parse($event['occurred_at'])->utc();
                if ($at->greaterThan($cutoff)) {
                    // An already imported event remains a duplicate even after a server clock rollback.
                    if (DB::table('hr_attendance_punches')->where('device_id', $device->id)
                        ->where('external_id', $event['external_id'])->exists()) {
                        $known++;

                        continue;
                    }
                    DB::table('hr_adms_quarantined_punches')->insert([
                        'device_id' => $device->id, 'upload_id' => $uploadId,
                        'external_id' => $event['external_id'], 'device_user_id' => $event['device_user_id'],
                        'occurred_at' => $at->format('Y-m-d H:i:s'), 'direction' => $event['direction'],
                        'reason' => 'future_event_time', 'status' => 'pending', 'received_at' => $this->now(),
                        'created_at' => $this->now(), 'updated_at' => $this->now(),
                    ]);
                    $quarantined++;

                    continue;
                }
                $valid[] = $event;
            }
            // Original body + held events + valid punches all commit before OK: N.
            $result = $this->importer->import($device, $valid, null);

            return ['received' => count($lines), 'imported' => $result['inserted'],
                'duplicates' => $known + $result['duplicates'], 'quarantined' => $quarantined];
        }, 3);
    }

    public function touch(AttendanceDevice $device): void
    {
        DB::transaction(function () use ($device) {
            $this->lock($device)->update(['last_seen_at' => CarbonImmutable::now('UTC')]);
        }, 3);
    }

    public function configure(AttendanceDevice $device, array $data): AttendanceDevice
    {
        return DB::transaction(function () use ($device, $data) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
            abort_if($this->activeEnrollment($device), 409, 'An enrollment is still active; wait for completion or timeout.');
            $old = $device->only(['connection_mode', 'adms_source_ip']);
            $data['adms_source_ip'] = ($data['connection_mode'] === 'adms') ? $data['adms_source_ip'] : null;
            if ($old !== $data) {
                $device->update($data + ['complete_through' => null]);
                // An approved day keeps its historical snapshot and requires explicit review.
                DB::table('hr_attendance_days')->whereNotNull('approved_at')->whereIn('employee_id',
                    AttendanceBinding::query()->where('device_id', $device->id)->select('employee_id'))
                    ->update(['pending_changes' => true]);
            }
            $this->audit->log('attendance.device_connection_configured', $device, $old, $data);

            return $device->refresh();
        }, 3);
    }

    public function startEnrollment(AttendanceBinding $binding, int $finger, int $actor): array
    {
        abort_unless(config('attendance.adms.remote_enrollment'), 409, 'Remote enrollment is experimental and disabled.');

        return DB::transaction(function () use ($binding, $finger, $actor) {
            $device = $this->lock(AttendanceDevice::findOrFail($binding->device_id));
            $binding = AttendanceBinding::query()->lockForUpdate()->findOrFail($binding->id);
            $today = CarbonImmutable::now($device->timezone)->toDateString();
            abort_if($binding->valid_from->format('Y-m-d') > $today
                || ($binding->valid_to && $binding->valid_to->format('Y-m-d') < $today), 422, 'Binding is not currently valid.');
            abort_unless(preg_match('/^[0-9]{1,12}$/D', $binding->device_user_id), 422, 'Remote enrollment requires a numeric PIN of 1–12 digits.');
            abort_if($this->activeEnrollment($device), 409, 'Another enrollment is active on this device.');
            $now = $this->now();
            $id = DB::table('hr_adms_enrollments')->insertGetId([
                'device_id' => $device->id, 'binding_id' => $binding->id, 'device_user_id' => $binding->device_user_id,
                'finger_id' => $finger, 'requested_by' => $actor, 'status' => 'queued_user',
                'expires_at' => CarbonImmutable::now('UTC')->addMinutes(config('attendance.adms.command_timeout_minutes')),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            // Safe ASCII name and no user-controlled command fragments; no biometric templates.
            $pin = $binding->device_user_id;
            $this->command($device->id, $id, 'DATA', "DATA UPDATE USERINFO PIN={$pin}\tName=EOMS_{$pin}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000000000000\tVerify=-1\tViceCard=");
            $this->audit->log('attendance.remote_enrollment_requested', $binding, null,
                ['enrollment_id' => $id, 'finger_id' => $finger], $actor);

            return $this->enrollment($id);
        }, 3);
    }

    public function poll(AttendanceDevice $device): string
    {
        return DB::transaction(function () use ($device) {
            $device = $this->lock($device);
            $device->update(['last_seen_at' => CarbonImmutable::now('UTC')]);
            $enrollment = $this->activeEnrollment($device);
            if (! $enrollment || ! config('attendance.adms.remote_enrollment')) {
                return 'OK';
            }
            $binding = AttendanceBinding::findOrFail($enrollment->binding_id);
            $today = CarbonImmutable::now($device->timezone)->toDateString();
            if ($binding->valid_from->format('Y-m-d') > $today || ($binding->valid_to && $binding->valid_to->format('Y-m-d') < $today)) {
                DB::table('hr_adms_enrollments')->where('id', $enrollment->id)->update(['status' => 'failed', 'updated_at' => $this->now()]);
                DB::table('hr_adms_commands')->where('enrollment_id', $enrollment->id)->where('status', 'queued')->update(['status' => 'cancelled']);

                return 'OK';
            }
            $command = DB::table('hr_adms_commands')->where('enrollment_id', $enrollment->id)->where('status', 'queued')->orderBy('id')->first();
            if (! $command) {
                return 'OK';
            }
            DB::table('hr_adms_commands')->where('id', $command->id)->update(['status' => 'delivered', 'delivered_at' => $this->now(), 'updated_at' => $this->now()]);
            DB::table('hr_adms_enrollments')->where('id', $enrollment->id)->update([
                'status' => $command->kind === 'DATA' ? 'await_user_ack' : 'await_enroll_ack', 'updated_at' => $this->now(),
            ]);

            // No automatic replay: the device may have executed a command whose ACK was lost.
            return "C:{$command->id}:{$command->payload}\n";
        }, 3);
    }

    public function acknowledge(AttendanceDevice $device, array $records): void
    {
        DB::transaction(function () use ($device, $records) {
            $device = $this->lock($device);
            $this->activeEnrollment($device);
            foreach ($records as $fields) {
                $command = DB::table('hr_adms_commands')->where('id', $fields['ID'])->where('device_id', $device->id)->first();
                if (! $command || $command->status !== 'delivered' || $command->kind !== strtoupper($fields['CMD'])) {
                    continue;
                }
                $enrollment = DB::table('hr_adms_enrollments')->where('id', $command->enrollment_id)->first();
                if (! in_array($enrollment->status, ['await_user_ack', 'await_enroll_ack'], true)) {
                    continue;
                }
                $success = $fields['Return'] === '0';
                DB::table('hr_adms_commands')->where('id', $command->id)->update([
                    'status' => $success ? 'acknowledged' : 'failed', 'return_code' => $fields['Return'],
                    'acknowledged_at' => $this->now(), 'updated_at' => $this->now(),
                ]);
                $status = ! $success ? 'failed' : ($command->kind === 'DATA' ? 'queued_enroll' : 'awaiting_physical_verification');
                DB::table('hr_adms_enrollments')->where('id', $enrollment->id)->update(['status' => $status, 'updated_at' => $this->now()]);
                if ($success && $command->kind === 'DATA') {
                    $this->command($device->id, $enrollment->id, 'ENROLL_FP',
                        "ENROLL_FP PIN={$enrollment->device_user_id}\tFID={$enrollment->finger_id}\tRETRY=3\tOVERWRITE=0");
                }
            }
            $device->update(['last_seen_at' => CarbonImmutable::now('UTC')]);
        }, 3);
    }

    public function enrollment(int $id): array
    {
        $row = DB::table('hr_adms_enrollments')->find($id);
        abort_unless($row, 404);
        DB::transaction(function () use ($row) {
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($row->device_id);
            $this->activeEnrollment($device);
        }, 3);

        return $this->enrollmentSnapshot($id);
    }

    private function enrollmentSnapshot(int $id): array
    {
        $row = DB::table('hr_adms_enrollments')->find($id);
        abort_unless($row, 404);
        $commands = DB::table('hr_adms_commands')->where('enrollment_id', $id)->orderBy('id')
            ->get(['id', 'kind', 'status', 'return_code', 'delivered_at', 'acknowledged_at']);

        return ['enrollment' => $row, 'commands' => $commands,
            'biometric_verified' => false, 'operator_confirmed' => $row->status === 'manually_confirmed',
            'physical_test_required' => $row->status !== 'manually_confirmed'];
    }

    public function confirm(int $id, int $actor, string $note): array
    {
        return DB::transaction(function () use ($id, $actor, $note) {
            $row = DB::table('hr_adms_enrollments')->find($id);
            abort_unless($row, 404);
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($row->device_id);
            $row = DB::table('hr_adms_enrollments')->where('id', $id)->lockForUpdate()->first();
            if ($row->status === 'manually_confirmed') {
                return $this->enrollmentSnapshot($id);
            }
            $device = $this->lock($device);
            $this->activeEnrollment($device);
            $row = DB::table('hr_adms_enrollments')->find($id);
            abort_unless(in_array($row->status, ['awaiting_physical_verification', 'timeout'], true), 409,
                'Enrollment cannot be confirmed in its current state.');
            abort_unless($this->hasSuccessfulEnrollmentCommands($row), 409,
                'A successful ENROLL_FP acknowledgement is required.');
            $binding = AttendanceBinding::query()->lockForUpdate()->findOrFail($row->binding_id);
            $today = CarbonImmutable::now($device->timezone)->toDateString();
            abort_unless((int) $binding->device_id === (int) $device->id && $binding->device_user_id === $row->device_user_id
                && $binding->valid_from->format('Y-m-d') <= $today
                && ($binding->valid_to === null || $binding->valid_to->format('Y-m-d') >= $today), 409,
                'The original employee binding is no longer active.');
            abort_if(DB::table('hr_adms_enrollments')->where('device_id', $device->id)
                ->where('device_user_id', $row->device_user_id)->where('id', '>', $id)->exists(), 409,
                'A newer enrollment exists for this device user; review the latest enrollment.');
            DB::table('hr_adms_enrollments')->where('id', $id)->update([
                'status' => 'manually_confirmed', 'confirmed_by' => $actor,
                'confirmed_at' => $this->now(), 'confirmation_note' => $note, 'updated_at' => $this->now(),
            ]);
            $this->audit->log('attendance.enrollment_manually_confirmed', AttendanceBinding::findOrFail($row->binding_id),
                ['status' => $row->status], ['enrollment_id' => $id, 'note' => $note, 'status' => 'manually_confirmed'], $actor);

            return $this->enrollmentSnapshot($id);
        }, 3);
    }

    private function hasSuccessfulEnrollmentCommands(object $enrollment): bool
    {
        // Both steps must have succeeded before the original command deadline.
        return DB::table('hr_adms_commands')->where('device_id', $enrollment->device_id)
            ->where('enrollment_id', $enrollment->id)->whereIn('kind', ['DATA', 'ENROLL_FP'])
            ->where('status', 'acknowledged')->where('return_code', '0')
            ->whereNotNull('delivered_at')->whereNotNull('acknowledged_at')
            ->whereColumn('delivered_at', '<=', 'acknowledged_at')
            ->where('acknowledged_at', '<', $enrollment->expires_at)->count() === 2;
    }

    private function activeEnrollment(AttendanceDevice $device): ?object
    {
        $now = $this->now();
        $expired = DB::table('hr_adms_enrollments')->where('device_id', $device->id)
            ->whereIn('status', ['queued_user', 'await_user_ack', 'queued_enroll', 'await_enroll_ack'])
            ->where('expires_at', '<=', $now)->pluck('id');
        if ($expired->isNotEmpty()) {
            DB::table('hr_adms_enrollments')->whereIn('id', $expired)->update(['status' => 'timeout', 'updated_at' => $now]);
            DB::table('hr_adms_commands')->whereIn('enrollment_id', $expired)->whereIn('status', ['queued', 'delivered'])
                ->update(['status' => 'timeout', 'updated_at' => $now]);
        }

        return DB::table('hr_adms_enrollments')->where('device_id', $device->id)
            ->whereIn('status', ['queued_user', 'await_user_ack', 'queued_enroll', 'await_enroll_ack', 'awaiting_physical_verification'])->orderBy('id')->first();
    }

    private function command(int $deviceId, int $enrollmentId, string $kind, string $payload): void
    {
        DB::table('hr_adms_commands')->insert(['device_id' => $deviceId, 'enrollment_id' => $enrollmentId,
            'kind' => $kind, 'payload' => $payload, 'status' => 'queued', 'created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    private function lock(AttendanceDevice $device): AttendanceDevice
    {
        $expectedIp = $device->adms_source_ip;
        $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($device->id);
        abort_unless($device->is_active && $device->connection_mode === 'adms' && $device->adms_source_ip === $expectedIp, 403);

        return $device;
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }
}
