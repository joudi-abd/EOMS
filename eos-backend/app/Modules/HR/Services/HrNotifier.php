<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceCorrection;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Notifications\HrNotification;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about leave and attendance events. Sending waits for the surrounding transaction to commit,
 * so a rolled-back action never notifies anyone.
 */
class HrNotifier
{
    private const LEAVE_DECISIONS = [
        'approve' => 'تم اعتماد طلب إجازتك.', 'reject' => 'تم رفض طلب إجازتك.',
        'approve-change' => 'تم اعتماد طلب إلغاء/تقصير إجازتك.', 'reject-change' => 'تم رفض طلب إلغاء/تقصير إجازتك.',
    ];

    public function leaveSubmitted(LeaveRequest $request, bool $amended): void
    {
        if ($request->entered_by !== null && ! $amended) {
            $this->send($this->users([$request->submitted_by]), 'leave.entered_for_you',
                'سجّلت الموارد البشرية طلب إجازة باسمك.', $this->leaveContext($request));
        }
        $this->send($this->leaveReviewers($request)->reject(fn (User $user) => $user->id === $request->entered_by), $amended ? 'leave.amended' : 'leave.submitted',
            ($amended ? 'تعديل على طلب إجازة: ' : 'طلب إجازة جديد: ').$this->employeeName($request), $this->leaveContext($request));
    }

    public function leaveChangeRequested(LeaveRequest $request): void
    {
        $this->send($this->leaveReviewers($request), 'leave.change_requested',
            'طلب إلغاء/تقصير إجازة: '.$this->employeeName($request), $this->leaveContext($request));
    }

    public function leaveDecided(LeaveRequest $request, string $action): void
    {
        if (isset(self::LEAVE_DECISIONS[$action])) {
            $this->send($this->users([$request->submitted_by]), 'leave.'.str_replace('-', '_', $action),
                self::LEAVE_DECISIONS[$action], $this->leaveContext($request));
        }
    }

    public function leaveClosed(LeaveRequest $request, string $kind, string $message, array $extra = []): void
    {
        $this->send($this->users([$request->submitted_by]), $kind, $message, [...$this->leaveContext($request), ...$extra]);
    }

    public function correctionRequested(AttendanceCorrection $correction, AttendanceDay $day): void
    {
        $approvers = app(Access::class)->usersWith('attendance.approve')
            ->reject(fn (User $user) => $user->id === $correction->requested_by);
        $this->send($approvers, 'attendance.correction_requested',
            'طلب تصحيح حضور ليوم '.$day->work_date->format('Y-m-d'), $this->dayContext($day) + ['correction_id' => $correction->id]);
    }

    public function correctionReviewed(AttendanceCorrection $correction, AttendanceDay $day): void
    {
        $this->send($this->users([$correction->requested_by]), 'attendance.correction_'.$correction->status,
            ($correction->status === 'approved' ? 'تم اعتماد' : 'تم رفض').' تصحيح الحضور ليوم '.$day->work_date->format('Y-m-d'),
            $this->dayContext($day) + ['correction_id' => $correction->id]);
    }

    public function attendanceConflict(AttendanceDay $day, string $flag): void
    {
        $this->send(app(Access::class)->usersWith('attendance.approve'), 'attendance.'.$flag,
            'تعارض حضور: بصمة في يوم إجازة معتمدة ('.$day->work_date->format('Y-m-d').')', $this->dayContext($day));
    }

    public function openingBalanceNeeded(Employee $employee): void
    {
        $this->send(app(Access::class)->usersWith('leaves.manage'), 'leave.opening_balance_needed',
            'عاد الموظف '.$employee->full_name.' للخدمة؛ سجّل رصيده الافتتاحي للإجازات.', ['employee_id' => $employee->id]);
    }

    /** Same routing as LeaveWorkflowService::mayReview. */
    public function leaveReviewers(LeaveRequest $request): Collection
    {
        $level = app(LeaveWorkflowService::class)->levelOf($request);

        return app(Access::class)->usersWith(LeaveWorkflowService::permissionFor($level))
            ->reject(fn (User $user) => $user->id === $request->submitted_by)->values();
    }

    private function send(Collection $users, string $kind, string $message, array $context): void
    {
        if ($users->isEmpty()) {
            return;
        }
        DB::afterCommit(fn () => Notification::send($users, new HrNotification($kind, $message, $context)));
    }

    private function users(array $ids): Collection
    {
        return User::query()->whereIn('id', array_filter($ids))->where('status', 'active')->get();
    }

    private function employeeName(LeaveRequest $request): string
    {
        return (string) Employee::find($request->employee_id)?->full_name;
    }

    private function leaveContext(LeaveRequest $request): array
    {
        return ['leave_request_id' => $request->id, 'employee_id' => $request->employee_id,
            'start_date' => $request->input['start_date'] ?? null, 'end_date' => $request->input['end_date'] ?? null];
    }

    private function dayContext(AttendanceDay $day): array
    {
        return ['attendance_day_id' => $day->id, 'employee_id' => $day->employee_id, 'date' => $day->work_date->format('Y-m-d')];
    }
}
