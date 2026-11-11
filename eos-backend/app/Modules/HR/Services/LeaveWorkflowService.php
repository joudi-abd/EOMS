<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Models\LeaveBalanceEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\Attendance\AttendanceLeaveSync;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveWorkflowService
{
    public function __construct(private LeaveBalanceService $balances, private EmploymentPeriodService $employment, private AuditLogger $audit, private AttendanceLeaveSync $attendance) {}

    /**
     * The employee a submission is for: the actor themself, or, for leaves.manage holders, another employee.
     * The request always belongs to that employee's own account.
     */
    public function subjectFor(User $actor, ?int $employeeId): Employee
    {
        $own = Employee::where('user_id', $actor->id)->first();
        if ($employeeId === null || $employeeId === $own?->id) {
            return $this->employeeFor($actor);
        }
        abort_unless($actor->can('leaves.manage'), 403, 'تقديم إجازة نيابة عن موظف يتطلب صلاحية إدارة الإجازات.');
        $employee = Employee::findOrFail($employeeId);
        abort_if($employee->user_id === null, 422, 'الموظف غير مرتبط بحساب.');

        return $employee;
    }

    /** Owner actions (edit, withdraw, cancel, shorten): the employee, or HR acting for them. */
    public function mayActFor(User $actor, LeaveRequest $request): bool
    {
        return $actor->id === $request->submitted_by || $actor->can('leaves.manage');
    }

    public function employeeFor(User $actor): Employee
    {
        $employee = Employee::where('user_id', $actor->id)->first();
        abort_unless($employee, 403, 'يلزم ربط الحساب بملف موظف.');
        return $employee;
    }

    private function period(Employee $employee): EmployeeServicePeriod
    {
        $today = $this->employment->today();
        $period = $employee->servicePeriods()->whereDate('start_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->first();
        abort_unless($period && $employee->status !== 'terminated', 422, 'لا توجد فترة خدمة سارية.');
        return $period;
    }

    public function options(User $actor, ?int $employeeId = null): array
    {
        // HR preparing a request for someone else sees that employee's balances.
        $employee = $employeeId === null ? Employee::where('user_id', $actor->id)->first() : $this->subjectFor($actor, $employeeId);
        $balances = [];
        $eligible = false;
        if ($employee) {
            DB::transaction(function () use ($employee, &$balances, &$eligible) {
                $employee = Employee::lockForUpdate()->findOrFail($employee->id);
                $this->expire($employee);
                $today = $this->employment->today();
                $period = $employee->servicePeriods()->whereDate('start_date', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->first();
                $eligible = $period !== null && $employee->status !== 'terminated';
                if (! $eligible) {
                    return;
                }
                $this->balances->sync($employee, $period);
                $awaiting = array_column($this->balances->pendingOpenings($employee, $period), 'leave_type_id');
                foreach (LeaveType::where('active', true)->get() as $type) {
                    $policy = $this->balances->policy($type->id, $today);
                    if (! $policy) {
                        continue;
                    }
                    [$start, $end] = $this->balances->cycle($policy->toArray(), $employee, $today);
                    $balances[] = ['id' => $type->id, 'name' => $type->name, 'pay_percent' => $policy->pay_percent,
                        'cycle_start' => $start, 'cycle_end' => $end,
                        // available_units stays for existing clients; the breakdown adds granted/used/reserved.
                        'available_units' => $policy->annual_days === null ? null : $this->balances->available($period->id, $type->id, $start),
                        'balance' => $policy->annual_days === null ? null : $this->balances->breakdown($period->id, $type->id, $start),
                        'awaiting_opening' => in_array($type->id, $awaiting, true)];
                }
            }, 3);
        }
        return ['types' => $balances, 'can_submit' => $eligible,
            'can_review' => $actor->can('leaves.approve') && $actor->hasAnyRole(['hr_manager', 'company_manager']),
            'can_configure' => $actor->can('leaves.manage'), 'today' => $this->employment->today(),
            'half_days' => DB::table('hr_leave_half_days')->find(1)];
    }

    public function preview(User $actor, array $input, ?LeaveRequest $request = null): array
    {
        $employee = $request ? Employee::findOrFail($request->employee_id) : $this->subjectFor($actor, $input['employee_id'] ?? null);
        return DB::transaction(function () use ($actor, $employee, $input, $request) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $employee = Employee::lockForUpdate()->findOrFail($employee->id);
            $this->expire($employee);
            $period = $this->period($employee);
            $this->balances->sync($employee, $period);
            if ($request) {
                $request->refresh();
                abort_unless($this->mayActFor($actor, $request) && $request->status === 'pending', 409, 'الطلب غير قابل للتعديل.');
                abort_unless($request->employee_id === $employee->id && $request->service_period_id === $period->id, 409, 'تغيّرت فترة الخدمة؛ اسحب الطلب القديم وأنشئ طلباً جديداً.');
                $policy = $request->policy;
            } else {
                $type = LeaveType::where('active', true)->findOrFail($input['leave_type_id']);
                $policy = $this->balances->policy($type->id, $this->employment->today())?->toArray();
                abort_unless($policy, 422, 'لا توجد سياسة سارية.');
            }
            $days = $this->plan($employee, $period, $policy, $input, $request);
            return $this->quote($days, $policy);
        }, 3);
    }

    private function quote(array $days, array $policy): array
    {
        return ['days' => $days, 'policy_id' => $policy['id'], 'pay_percent' => $policy['pay_percent'],
            'total_units' => array_sum(array_column($days, 'day_units')),
            'covered_units' => array_sum(array_column($days, 'covered_units')),
            'unpaid_units' => array_sum(array_column($days, 'unpaid_units')),
            'quote_token' => hash_hmac('sha256', json_encode([$days, $policy]), (string) config('app.key'))];
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function plan(Employee $employee, EmployeeServicePeriod $period, array $policy, array $input, ?LeaveRequest $editing): array
    {
        $today = $this->employment->today();
        $start = CarbonImmutable::parse($input['start_date']);
        $end = CarbonImmutable::parse($input['end_date']);
        if ($start->toDateString() < CarbonImmutable::parse($today)->subDays($policy['retroactive_days'])->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'الطلب يتجاوز عدد الأيام المسموح بها بأثر رجعي.']);
        }
        if ($start->toDateString() < $period->start_date->toDateString()
            || ($period->end_date && $end->toDateString() > $period->end_date->toDateString())) {
            throw ValidationException::withMessages(['start_date' => 'كل تواريخ الإجازة يجب أن تقع ضمن فترة الخدمة الحالية.']);
        }
        if ($start->diffInDays($end, false) < 0 || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['end_date' => 'حد الطلب 366 يوماً مرتبة زمنياً.']);
        }
        if ($input['unit'] !== 'day' && $start->toDateString() !== $end->toDateString()) {
            throw ValidationException::withMessages(['end_date' => 'الإجازة الجزئية تخص يوماً واحداً.']);
        }
        $holidayDates = OfficeHoliday::whereBetween('date', [$input['start_date'], $input['end_date']])->pluck('date')
            ->map(fn ($date) => substr((string) $date, 0, 10))->all();
        $halves = (array) DB::table('hr_leave_half_days')->find(1);
        $remaining = [];
        $result = [];
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $day = $date->toDateString();
            $schedule = AttendancePolicy::whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->first();
            abort_unless($schedule, 422, 'يلزم إعداد سياسة دوام تغطي تاريخ الإجازة.');
            if (! in_array($date->dayOfWeek, $schedule->working_days, true) || in_array($day, $holidayDates, true)) {
                continue;
            }
            $collision = LeaveRequestDay::where('employee_id', $employee->id)->where('date', $day)->where('active', true)
                ->when($editing, fn ($q) => $q->where('leave_request_id', '!=', $editing->id))->exists();
            if ($collision) {
                throw ValidationException::withMessages(['start_date' => "يوجد طلب آخر في {$day}؛ مسموح طلب واحد يومياً."]);
            }
            $from = substr($schedule->start_time, 0, 5);
            $to = substr($schedule->end_time, 0, 5);
            $duration = $this->minutes($to) - $this->minutes($from);
            abort_unless($duration > 0, 422, 'يلزم دوام نهاري صالح لحساب الإجازة.');
            if ($input['unit'] === 'hours') {
                $from = $input['start_time'];
                $to = $input['end_time'];
            } elseif ($input['unit'] === 'half') {
                $from = $halves[$input['half'].'_start'];
                $to = $halves[$input['half'].'_end'];
            }
            // unit=day stores the then-current schedule hours; attendance treats day_units=1_000_000 as a full day
            // against whatever schedule is later in force (see AttendanceCalculator::leaveWindow).
            $minutes = $this->minutes($to) - $this->minutes($from);
            if ($minutes <= 0 || $this->minutes($from) < $this->minutes($schedule->start_time)
                || $this->minutes($to) > $this->minutes($schedule->end_time)) {
                throw ValidationException::withMessages(['start_time' => "الفترة يجب أن تكون ضمن ساعات دوام {$day}."]);
            }
            [$cycle, $cycleEnd] = $this->balances->cycle($policy, $employee, $day);
            abort_if($cycleEnd < $today, 422, 'انتهت سنة رصيد هذا اليوم؛ يلزم مراجعة الموارد البشرية.');
            $units = (int) round($minutes * 1_000_000 / $duration);
            $covered = $units;
            if ($policy['annual_days'] !== null) {
                if (! array_key_exists($cycle, $remaining)) {
                    $remaining[$cycle] = $this->balances->available($period->id, $policy['leave_type_id'], $cycle);
                    if ($editing) {
                        $remaining[$cycle] += (int) $editing->days()->where('active', true)->where('cycle_start', $cycle)->sum('covered_units');
                    }
                }
                $covered = min($units, max(0, $remaining[$cycle]));
                $remaining[$cycle] -= $covered;
            }
            $result[] = ['employee_id' => $employee->id, 'date' => $day, 'cycle_start' => $cycle, 'cycle_end' => $cycleEnd,
                'start_time' => $from, 'end_time' => $to, 'minutes' => $minutes, 'day_minutes' => $duration,
                'day_units' => $units, 'covered_units' => $covered, 'unpaid_units' => $units - $covered,
                'pay_percent' => $policy['pay_percent'], 'schedule' => $schedule->toArray()];
        }
        abort_if($result === [], 422, 'الفترة لا تتضمن ساعات دوام قابلة للإجازة.');
        return $result;
    }

    public function submit(User $actor, array $input, ?LeaveRequest $editing = null): LeaveRequest
    {
        $employee = $editing ? Employee::findOrFail($editing->employee_id) : $this->subjectFor($actor, $input['employee_id'] ?? null);
        return DB::transaction(function () use ($actor, $employee, $input, $editing) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $employee = Employee::lockForUpdate()->findOrFail($employee->id);
            $this->expire($employee);
            if (! $editing) {
                $existing = LeaveRequest::where('submission_key', $input['submission_key'])->first();
                if ($existing) {
                    abort_unless(in_array($actor->id, [$existing->submitted_by, $existing->entered_by], true), 409, 'مفتاح الطلب مستخدم.');
                    $submitted = array_intersect_key($input, array_flip(['start_date', 'end_date', 'unit', 'half', 'start_time', 'end_time', 'reason']));
                    abort_unless($existing->input == $submitted && $existing->leave_type_id === (int) $input['leave_type_id'], 409, 'هذا المفتاح يخص طلباً مختلفاً؛ حدّث القائمة قبل إرسال طلب جديد.');
                    return $existing->load('days');
                }
            } else {
                $editing->refresh();
                abort_unless($this->mayActFor($actor, $editing) && $editing->status === 'pending', 409, 'الطلب غير قابل للتعديل.');
                $this->version($editing, $input);
            }
            $owner = User::findOrFail($employee->user_id);
            $period = $this->period($employee);
            $this->balances->sync($employee, $period);
            if ($editing) {
                abort_unless($editing->employee_id === $employee->id && $editing->service_period_id === $period->id, 409, 'تغيّرت فترة الخدمة؛ اسحب الطلب القديم وأنشئ طلباً جديداً.');
                $policy = $editing->policy;
            } else {
                $type = LeaveType::where('active', true)->findOrFail($input['leave_type_id']);
                $policy = $this->balances->policy($type->id, $this->employment->today())?->toArray();
                abort_unless($policy, 422, 'لا توجد سياسة سارية.');
            }
            $days = $this->plan($employee, $period, $policy, $input, $editing);
            $quote = $this->quote($days, $policy);
            abort_unless(hash_equals($quote['quote_token'], $input['quote_token']), 409, 'تغيّر الحساب؛ اعرض المعاينة مجدداً.');
            if ($quote['unpaid_units'] > 0 && ! ($input['unpaid_consent'] ?? false)) {
                throw ValidationException::withMessages(['unpaid_consent' => 'يلزم قبول الجزء غير المغطى كإجازة بدون راتب.']);
            }
            $values = ['input' => array_intersect_key($input, array_flip(['start_date', 'end_date', 'unit', 'half', 'start_time', 'end_time', 'reason'])),
                'expires_on' => min(array_column($days, 'cycle_end')), 'unpaid_consent' => $input['unpaid_consent'] ?? false];
            if ($editing) {
                $this->release($editing, $actor->id, 'تعديل الطلب');
                $editing->update([...$values, 'version' => $editing->version + 1]);
                $request = $editing;
            } else {
                $request = LeaveRequest::create([...$values, 'employee_id' => $employee->id, 'service_period_id' => $period->id,
                    'leave_type_id' => $policy['leave_type_id'], 'leave_policy_id' => $policy['id'],
                    'policy' => $policy, 'submitted_by' => $owner->id, 'submission_key' => $input['submission_key'],
                    'entered_by' => $owner->id === $actor->id ? null : $actor->id,
                    'approver_role' => $owner->hasRole('hr_manager') ? 'company_manager' : 'hr_manager']);
            }
            foreach ($days as $data) {
                $day = $request->days()->create($data);
                if ($policy['annual_days'] !== null && $day->covered_units > 0) {
                    $this->entry($request, $day, -$day->covered_units, 'reserve', 'حجز رصيد الطلب', $actor->id);
                }
            }
            $this->audit->log($editing ? 'leave.request_amended' : 'leave.request_submitted', $request, null, $request->getAttributes(), $actor->id);
            if (! $editing && $this->selfApproves($owner)) {
                // The company manager has no approver above them: the leave is recorded as approved immediately.
                $this->consume($request, $actor->id);
                $request->update(['status' => 'approved', 'approver_role' => 'company_manager', 'reviewed_by' => $actor->id,
                    'review_note' => 'اعتماد مباشر: إجازة مدير الشركة', 'version' => $request->version + 1]);
                $this->audit->log('leave.auto_approved', $request, null, $request->getAttributes(), $actor->id);
                $this->attendance->employeeDates($employee->id, $request->days()->pluck('date'));
            } else {
                app(HrNotifier::class)->leaveSubmitted($request, (bool) $editing);
            }
            // Database defaults (including nullable review fields) belong in the first API response too.
            return $request->refresh()->load('days');
        }, 3);
    }

    private function version(LeaveRequest $request, array $data): void
    {
        abort_unless($request->version === (int) $data['version'], 409, 'تغيّر الطلب؛ حدّث الصفحة قبل المتابعة.');
    }

    /** The company manager's own leave needs no review, whoever records it. */
    public function selfApproves(User $owner): bool
    {
        return $owner->hasRole('company_manager');
    }

    public function mayReview(User $actor, LeaveRequest $request): bool
    {
        $submitter = User::find($request->submitted_by);
        // Role changes cannot let HR approve another HR member's own request.
        $role = $request->approver_role === 'company_manager' || $submitter?->hasRole('hr_manager') ? 'company_manager' : 'hr_manager';
        return $actor->id !== $request->submitted_by && $actor->can('leaves.approve') && $actor->hasRole($role);
    }

    public function action(User $actor, LeaveRequest $request, string $action, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $action, $data) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $employee = Employee::lockForUpdate()->findOrFail($request->employee_id);
            $this->expire($employee);
            $request->refresh();
            $this->version($request, $data);
            $own = $this->mayActFor($actor, $request);
            $old = $request->getAttributes();
            if ($action === 'withdraw') {
                abort_unless($own, 403);
                abort_unless($request->status === 'pending', 409);
                $this->release($request, $actor->id, 'سحب الطلب');
                $request->status = 'withdrawn';
            } elseif (in_array($action, ['approve', 'reject'], true)) {
                abort_unless($this->mayReview($actor, $request), 403);
                abort_unless($request->status === 'pending', 409);
                if ($action === 'approve') {
                    $period = $this->period($employee);
                    abort_unless($period->id === $request->service_period_id, 409, 'تغيّرت فترة خدمة الموظف.');
                    if ($period->end_date) {
                        abort_if($request->days()->where('active', true)->where('date', '>', $period->end_date->toDateString())->exists(), 409, 'تتجاوز الإجازة نهاية الخدمة.');
                    }
                    $this->consume($request, $actor->id);
                    $request->status = 'approved';
                } else {
                    $this->release($request, $actor->id, 'رفض الطلب');
                    $request->status = 'rejected';
                }
                $request->reviewed_by = $actor->id;
                $request->review_note = $data['reason'];
            } elseif (in_array($action, ['cancel', 'shorten'], true)) {
                abort_unless($own, 403);
                abort_unless($request->status === 'approved' && $request->change_request === null, 409);
                $from = $action === 'cancel' ? $request->days()->where('active', true)->min('date') : $data['from_date'];
                $this->ensureUnused($request, $from, $action);
                $request->change_request = ['kind' => $action, 'from_date' => $from, 'reason' => $data['reason']];
                if ($this->selfApproves(User::findOrFail($request->submitted_by))) {
                    // Same rule as submission: the company manager's own cancel/shorten applies immediately.
                    $this->release($request, $actor->id, 'إلغاء مباشر من مدير الشركة', $from);
                    if (! $request->days()->where('active', true)->exists()) {
                        $request->status = 'cancelled';
                    }
                    $request->change_request = null;
                    $request->reviewed_by = $actor->id;
                    $request->review_note = $data['reason'];
                    $applied = true;
                }
            } elseif (in_array($action, ['approve-change', 'reject-change'], true)) {
                abort_unless($this->mayReview($actor, $request), 403);
                abort_unless($request->status === 'approved' && $request->change_request !== null, 409);
                if ($action === 'approve-change') {
                    $change = $request->change_request;
                    $this->ensureUnused($request, $change['from_date'], $change['kind']);
                    $this->release($request, $actor->id, 'اعتماد إلغاء الجزء غير المستخدم', $change['from_date']);
                    if (! $request->days()->where('active', true)->exists()) {
                        $request->status = 'cancelled';
                    }
                }
                $request->change_request = null;
                $request->review_note = $data['reason'];
                $request->reviewed_by = $actor->id;
            } else {
                abort(404);
            }
            $request->version++;
            $request->save();
            $this->audit->log('leave.'.$action, $request, $old, $request->getAttributes(), $actor->id);
            // Only approved leave changes attendance: approval adds it, an approved cancel/shorten removes it.
            if ($action === 'approve' || $action === 'approve-change' || ($applied ?? false)) {
                $this->attendance->employeeDates($request->employee_id, $request->days()->pluck('date'));
            }
            if (in_array($action, ['cancel', 'shorten'], true) && ! ($applied ?? false)) {
                app(HrNotifier::class)->leaveChangeRequested($request);
            } else {
                app(HrNotifier::class)->leaveDecided($request, $action);
            }
            return $request->load('days');
        }, 3);
    }

    private function ensureUnused(LeaveRequest $request, string $from, string $kind): void
    {
        $days = $request->days()->where('active', true)->where('date', '>=', $from)->get();
        abort_if($days->isEmpty(), 422, 'لا يوجد جزء قابل للإلغاء.');
        if ($kind === 'shorten') {
            abort_unless($request->days()->where('active', true)->where('date', '<', $from)->exists(), 422, 'لتقصير الإجازة يجب الإبقاء على يوم واحد على الأقل.');
        }
        foreach ($days as $day) {
            $tz = $day->schedule['timezone'] ?? 'UTC';
            $start = CarbonImmutable::parse($day->date.' '.substr((string) $day->start_time, 0, 5), $tz);
            abort_if($start->lessThanOrEqualTo(CarbonImmutable::now($tz)), 422, 'بدأت الفترة المطلوبة؛ اختر أياماً لم تبدأ بعد.');
        }
    }

    private function entry(LeaveRequest $request, LeaveRequestDay $day, int $units, string $kind, string $reason, int $actor): void
    {
        LeaveBalanceEntry::create(['service_period_id' => $request->service_period_id, 'leave_type_id' => $request->leave_type_id,
            'leave_policy_id' => $request->leave_policy_id, 'cycle_start' => $day->cycle_start,
            'leave_request_day_id' => $day->id, 'kind' => $kind, 'day_units' => $units,
            'reason' => $reason, 'recorded_by_user_id' => $actor]);
    }

    /**
     * Approval turns each day's reservation into a debit: the reservation is released and the same units are
     * debited, so availability is unchanged while the ledger shows what was actually consumed.
     */
    private function consume(LeaveRequest $request, int $actor): void
    {
        if ($request->policy['annual_days'] === null) {
            return;
        }
        foreach ($request->days()->where('active', true)->where('covered_units', '>', 0)->get() as $day) {
            $this->entry($request, $day, $day->covered_units, 'release', 'تحويل الحجز إلى خصم عند الاعتماد', $actor);
            $this->entry($request, $day, -$day->covered_units, 'debit', 'خصم إجازة معتمدة', $actor);
        }
    }

    /** Pending days give their reservation back ("release"); approved days give back a debit ("refund"). */
    private function release(LeaveRequest $request, int $actor, string $reason, ?string $from = null): void
    {
        $kind = $request->status === 'approved' ? 'refund' : 'release';
        foreach ($request->days()->where('active', true)->when($from, fn ($q) => $q->where('date', '>=', $from))->get() as $day) {
            if ($request->policy['annual_days'] !== null && $day->covered_units > 0) {
                $this->entry($request, $day, $day->covered_units, $kind, $reason, $actor);
            }
            $day->update(['active' => false, 'inactive_reason' => $kind === 'refund' ? 'cancelled' : 'released',
                'inactive_from_status' => $request->status]);
        }
    }

    /** A cross-year pending request closes as a whole; refunds stay in each original cycle. */
    public function expire(Employee $employee): void
    {
        foreach (LeaveRequest::where('employee_id', $employee->id)->where('status', 'pending')
            ->where('expires_on', '<', $this->employment->today())->get() as $request) {
            $this->release($request, 0, 'انتهاء سنة الرصيد');
            $request->update(['status' => 'expired', 'version' => $request->version + 1]);
            $this->audit->log('leave.request_expired', $request);
            app(HrNotifier::class)->leaveClosed($request, 'leave.expired', 'انتهى طلب إجازتك المعلّق بانتهاء سنة الرصيد.');
        }
    }


    /**
     * Leave cannot outlive the service period: days after the end date are released back to the period's ledger.
     * Caller holds the employee row lock inside the termination transaction. The calendar lock is deliberately
     * not taken here, because leave actions lock calendar → employee and termination already holds the employee.
     */
    public function serviceEnded(Employee $employee, string $endDate, int $actor): void
    {
        $from = CarbonImmutable::parse($endDate)->addDay()->toDateString();
        $requests = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereHas('days', fn ($q) => $q->where('active', true)->where('date', '>=', $from))->orderBy('id')->get();
        foreach ($requests as $request) {
            $old = $request->getAttributes();
            $released = $request->days()->where('active', true)->where('date', '>=', $from)->pluck('date');
            $this->release($request, $actor, 'انتهاء الخدمة', $from);
            if (! $request->days()->where('active', true)->exists()) {
                $request->status = 'cancelled';
            } elseif ($request->status === 'pending') {
                $request->expires_on = $request->days()->where('active', true)->min('cycle_end');
            }
            // A pending cancel/shorten was written against days that may no longer exist; it must be re-requested.
            $request->change_request = null;
            $request->version++;
            $request->save();
            $this->audit->log('leave.service_ended', $request, $old, ['end_date' => $endDate, 'released_dates' => $released->all()], $actor);
            $this->attendance->employeeDates($employee->id, $released);
        }
    }

    /** Caller holds the calendar lock, within the holiday creation transaction. */
    public function holidayAdded(string $date): void
    {
        $employeeIds = LeaveRequestDay::where('date', $date)->where('active', true)->distinct()->orderBy('employee_id')->pluck('employee_id');
        foreach ($employeeIds as $employeeId) {
            Employee::lockForUpdate()->findOrFail($employeeId);
            foreach (LeaveRequestDay::where('employee_id', $employeeId)->where('date', $date)->where('active', true)->get() as $day) {
                $request = LeaveRequest::findOrFail($day->leave_request_id);
                $fromStatus = $request->status;
                if ($request->policy['annual_days'] !== null && $day->covered_units > 0) {
                    $this->entry($request, $day, $day->covered_units, 'holiday_refund', 'استثناء عطلة رسمية من الإجازة', (int) auth()->id());
                }
                // Reason/status are stored on the day so restore does not depend on balance-entry kinds.
                $day->update(['active' => false, 'inactive_reason' => 'holiday', 'inactive_from_status' => $fromStatus]);
                $request->version++;
                if (! $request->days()->where('active', true)->exists()) {
                    $request->status = 'cancelled';
                    $request->change_request = null;
                } else {
                    $request->expires_on = $request->days()->where('active', true)->min('cycle_end');
                }
                $request->save();
                $this->audit->log('leave.holiday_refunded', $request, null, ['date' => $date, 'day_units' => $day->covered_units]);
                app(HrNotifier::class)->leaveClosed($request, 'leave.holiday_refunded', 'أُضيفت عطلة رسمية يوم '.$date.' ضمن إجازتك، وأُعيد رصيدها.', ['date' => $date]);
            }
        }
    }

    /**
     * Undo holidayAdded for days marked inactive_reason=holiday (and pre-column days still linked by
     * holiday_refund). Re-applies reserve/debit only when balance remains and the day is still inside
     * the original service period; otherwise the day stays inactive.
     */
    public function holidayRemoved(string $date): void
    {
        $days = LeaveRequestDay::query()->where('date', $date)->where('active', false)
            ->where(function ($query) {
                $query->where('inactive_reason', 'holiday')
                    ->orWhere(function ($legacy) {
                        // Rows deactivated before inactive_reason existed keep the holiday_refund link.
                        $legacy->whereNull('inactive_reason')
                            ->whereIn('id', LeaveBalanceEntry::query()->where('kind', 'holiday_refund')->select('leave_request_day_id'));
                    });
            })
            ->orderBy('employee_id')->orderBy('id')->get();
        foreach ($days->groupBy('employee_id') as $employeeId => $employeeDays) {
            Employee::lockForUpdate()->findOrFail($employeeId);
            if (LeaveRequestDay::query()->where('employee_id', $employeeId)->where('date', $date)->where('active', true)->exists()) {
                continue;
            }
            foreach ($employeeDays as $day) {
                $request = LeaveRequest::query()->lockForUpdate()->findOrFail($day->leave_request_id);
                if (! in_array($request->status, ['cancelled', 'approved', 'pending'], true)) {
                    continue;
                }
                $period = EmployeeServicePeriod::query()->find($request->service_period_id);
                if ($period === null
                    || $date < $period->start_date->toDateString()
                    || ($period->end_date && $date > $period->end_date->toDateString())) {
                    $this->audit->log('leave.holiday_restore_skipped', $request, null, [
                        'date' => $date, 'reason' => 'outside_service_period',
                    ]);

                    continue;
                }
                $restoreAs = $day->inactive_from_status;
                if ($restoreAs === null) {
                    $restoreAs = LeaveBalanceEntry::query()->where('leave_request_day_id', $day->id)
                        ->where('kind', 'debit')->exists() ? 'approved' : 'pending';
                }
                if ($request->policy['annual_days'] !== null && $day->covered_units > 0) {
                    $available = $this->balances->available(
                        (int) $request->service_period_id, (int) $request->leave_type_id, (string) $day->cycle_start
                    );
                    if ($available < $day->covered_units) {
                        $this->audit->log('leave.holiday_restore_skipped', $request, null, [
                            'date' => $date, 'needed' => $day->covered_units, 'available' => $available,
                        ]);

                        continue;
                    }
                    $kind = $restoreAs === 'approved' ? 'debit' : 'reserve';
                    $this->entry($request, $day, -$day->covered_units, $kind, 'إعادة احتساب إجازة بعد حذف العطلة', (int) auth()->id());
                }
                $day->update(['active' => true, 'inactive_reason' => null, 'inactive_from_status' => null]);
                if ($request->status === 'cancelled') {
                    $request->status = in_array($restoreAs, ['approved', 'pending'], true) ? $restoreAs : 'approved';
                }
                if ($request->status === 'pending') {
                    $request->expires_on = $request->days()->where('active', true)->min('cycle_end');
                }
                $request->version++;
                $request->save();
                $this->audit->log('leave.holiday_restored', $request, null, ['date' => $date, 'day_units' => $day->covered_units]);
                app(HrNotifier::class)->leaveClosed($request, 'leave.holiday_restored', 'حُذفت العطلة الرسمية يوم '.$date.' وأُعيدت إجازتك لذلك اليوم.', ['date' => $date]);
            }
        }
    }

    public function maintain(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            DB::table('hr_leave_half_days')->lockForUpdate()->find(1);
            $employee = Employee::lockForUpdate()->findOrFail($employee->id);
            $this->expire($employee);
            $today = $this->employment->today();
            $period = $employee->servicePeriods()->whereDate('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))->first();
            if ($period) {
                $this->balances->sync($employee, $period);
            }
        }, 3);
    }
}
