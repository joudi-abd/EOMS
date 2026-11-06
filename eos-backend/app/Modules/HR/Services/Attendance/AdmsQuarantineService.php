<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdmsQuarantineService
{
    public function __construct(private AttendanceImportService $importer, private AuditLogger $audit) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = DB::table('hr_adms_quarantined_punches')->select([
            'id', 'device_id', 'device_user_id', 'occurred_at', 'direction', 'reason', 'status',
            'received_at', 'reviewed_by', 'reviewed_at', 'review_note', 'punch_id',
        ]);
        if (isset($filters['device_id'])) {
            $query->where('device_id', $filters['device_id']);
        }
        $query->where('status', $filters['status'] ?? 'pending');

        return $query->orderBy('id')->paginate($filters['per_page'] ?? 50)->through(fn ($row) => $this->format($row));
    }

    public function review(int $id, array $data, int $actor): object
    {
        // A deliberate time correction must still satisfy normal attendance validation.
        $at = $data['action'] === 'release' ? AttendanceClock::utc($data['corrected_occurred_at']) : null;

        return DB::transaction(function () use ($id, $data, $actor, $at) {
            $source = DB::table('hr_adms_quarantined_punches')->find($id);
            abort_unless($source, 404);
            $device = AttendanceDevice::query()->lockForUpdate()->findOrFail($source->device_id);
            $source = DB::table('hr_adms_quarantined_punches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($source->status === 'pending', 409, 'This record has already been reviewed.');
            $punchId = null;
            if ($at) {
                abort_unless($device->is_active && $device->connection_mode === 'adms', 409, 'Device must be active in ADMS mode.');
                $this->importer->import($device, [[
                    'external_id' => $source->external_id, 'device_user_id' => $source->device_user_id,
                    'occurred_at' => $at->toIso8601String(), 'direction' => $source->direction,
                ]], null);
                $punchId = DB::table('hr_attendance_punches')->where('device_id', $device->id)
                    ->where('external_id', $source->external_id)->value('id');
            }
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
            $status = $at ? 'released' : 'dismissed';
            DB::table('hr_adms_quarantined_punches')->where('id', $id)->update([
                'status' => $status, 'punch_id' => $punchId, 'reviewed_by' => $actor,
                'reviewed_at' => $now, 'review_note' => $data['reason'], 'updated_at' => $now,
            ]);
            $this->audit->log('attendance.quarantined_punch_reviewed', $device, null, [
                'quarantine_id' => $id, 'action' => $data['action'], 'reason' => $data['reason'],
                'original_occurred_at_utc' => $source->occurred_at,
                'corrected_occurred_at_utc' => $at?->format('Y-m-d H:i:s'), 'punch_id' => $punchId,
            ], $actor);

            return $this->format(DB::table('hr_adms_quarantined_punches')->find($id));
        }, 3);
    }

    private function format(object $row): object
    {
        foreach (['occurred_at', 'received_at', 'reviewed_at', 'created_at', 'updated_at'] as $field) {
            if (isset($row->$field)) {
                $row->$field = CarbonImmutable::parse($row->$field, 'UTC')->toIso8601String();
            }
        }

        return $row;
    }
}
