<?php

namespace App\Modules\HR\Services\Attendance;

use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceReviewService
{
    public function __construct(private AttendanceCalculator $calculator, private AuditLogger $audit) {}

    private function current(AttendanceDay $day, int $version): AttendanceDay
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($day->employee_id);
        $day = $this->calculator->calculate($employee, $day->work_date->format('Y-m-d'));
        abort_if($day->version !== $version, 409, 'تغيرت البيانات؛ أعد تحميل اليوم قبل الحفظ.');

        return $day;
    }

    public function request(AttendanceDay $day, array $data, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($day, $data, $actor) {
            $day = $this->current($day, $data['version']);
            abort_if($day->approved_at !== null, 409, 'اليوم معتمد؛ أعد فتحه قبل التصحيح.');
            if (in_array($day->status, ['outside_service', 'day_off'], true)) {
                throw ValidationException::withMessages(['day' => ['تصحيح يوم راحة أو خارج الخدمة غير مدعوم في هذه النسخة.']]);
            }
            if (AttendanceCorrection::query()->where('day_id', $day->id)->where('status', 'pending')->exists()) {
                abort(409, 'يوجد طلب تصحيح قيد المراجعة لهذا اليوم.');
            }
            $first = $last = null;
            if ($data['type'] === 'times') {
                $first = AttendanceClock::utc($data['first_in']);
                $last = AttendanceClock::utc($data['last_out']);
                $policy = AttendancePolicy::findOrFail($day->policy_id);
                if (! $last->greaterThan($first)
                    || $first->setTimezone($policy->timezone)->toDateString() !== $day->work_date->format('Y-m-d')
                    || $last->setTimezone($policy->timezone)->toDateString() !== $day->work_date->format('Y-m-d')) {
                    throw ValidationException::withMessages(['last_out' => ['يجب أن يكون الخروج بعد الدخول وكلاهما ضمن نفس اليوم المحلي.']]);
                }
            }
            $correction = AttendanceCorrection::create([
                'day_id' => $day->id, 'type' => $data['type'], 'first_in' => $first, 'last_out' => $last,
                'reason' => $data['reason'], 'requested_by' => $actor->id, 'base_version' => $day->version,
            ]);
            $this->audit->log('attendance.correction_requested', $correction, null, ['day_id' => $day->id]);

            return $correction->refresh();
        }, 3);
    }

    public function review(AttendanceCorrection $correction, array $data, User $actor): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $data, $actor) {
            $day = AttendanceDay::findOrFail($correction->day_id);
            Employee::query()->lockForUpdate()->findOrFail($day->employee_id);
            $day = $this->calculator->calculate(Employee::findOrFail($day->employee_id), $day->work_date->format('Y-m-d'));
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            abort_if($correction->status !== 'pending', 409, 'تمت مراجعة الطلب سابقاً.');
            abort_if((int) $correction->requested_by === (int) $actor->id, 403, 'لا يمكنك مراجعة طلبك بنفسك.');
            abort_if($day->approved_at !== null, 409, 'اليوم معتمد.');
            // A stale request may be rejected, but must never be silently accepted.
            if ($data['decision'] === 'approve') {
                abort_if($day->version !== $correction->base_version, 409, 'وصلت بيانات جديدة؛ ارفض الطلب وأعد إنشاءه بعد المراجعة.');
            }
            $correction->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id, 'reviewed_at' => CarbonImmutable::now('UTC'),
                'review_reason' => $data['reason'],
            ]);
            $this->audit->log('attendance.correction_reviewed', $correction, null, ['status' => $correction->status]);
            $this->calculator->calculate(Employee::findOrFail($day->employee_id), $day->work_date->format('Y-m-d'));

            return $correction->refresh();
        }, 3);
    }

    public function approve(AttendanceDay $day, int $version, User $actor): AttendanceDay
    {
        return DB::transaction(function () use ($day, $version, $actor) {
            $day = $this->current($day, $version);
            abort_if($day->approved_at !== null, 409, 'اليوم معتمد سابقاً.');
            $policy = AttendancePolicy::findOrFail($day->policy_id);
            abort_if(CarbonImmutable::now('UTC')->lessThan(AttendanceClock::at($day->work_date->format('Y-m-d'), $policy->end_time, $policy->timezone)), 409, 'لم ينته وقت الدوام بعد.');
            abort_if(AttendanceCorrection::query()->where('day_id', $day->id)->where('status', 'pending')->exists(), 409, 'يوجد تصحيح معلق.');
            $manual = in_array('manual_correction', $day->flags, true);
            abort_unless(in_array($day->status, ['present', 'absent', 'day_off', 'outside_service'], true), 409, 'السجل غير مكتمل للاعتماد.');
            abort_if(in_array('leave_requires_review', $day->flags, true), 409, 'يجب تسوية حالة الإجازة قبل الاعتماد.');
            abort_if(! $day->coverage_complete && ! $manual && ! in_array($day->status, ['day_off', 'outside_service'], true), 409, 'المزامنة غير مؤكدة؛ يلزم تأكيد المصدر أو تصحيح معتمد.');
            $day->update(['approved_by' => $actor->id, 'approved_at' => CarbonImmutable::now('UTC'), 'version' => $day->version + 1]);
            $this->audit->log('attendance.day_approved', $day);

            return $day->refresh();
        }, 3);
    }

    public function reopen(AttendanceDay $day, int $version, string $reason, User $actor): AttendanceDay
    {
        return DB::transaction(function () use ($day, $version, $reason, $actor) {
            $day = $this->current($day, $version);
            abort_if($day->approved_at === null, 409, 'اليوم غير معتمد.');
            $day->update(['approved_by' => null, 'approved_at' => null, 'pending_changes' => false, 'version' => $day->version + 1]);
            $this->audit->log('attendance.day_reopened', $day, null, ['reason' => $reason, 'actor_id' => $actor->id]);

            return $this->calculator->calculate(Employee::findOrFail($day->employee_id), $day->work_date->format('Y-m-d'));
        }, 3);
    }
}
