<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use App\Modules\HR\Models\EmployeeServicePeriod;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeRehireService
{
    public function __construct(private AuditLogger $audit) {}

    public function rehire(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($employee->status !== 'terminated' || ! $employee->termination_date) {
                throw ValidationException::withMessages(['employee' => ['إعادة الخدمة متاحة لموظف منتهية خدمته وله تاريخ إنهاء مسجل فقط.']]);
            }
            $lastEnd = $employee->termination_date->format('Y-m-d');
            if ($data['effective_date'] <= $lastEnd || $data['effective_date'] > app(EmploymentPeriodService::class)->today()) {
                throw ValidationException::withMessages(['effective_date' => ['تاريخ العودة يجب أن يلي آخر يوم خدمة وألا يكون مستقبلياً.']]);
            }
            if (EmploymentContract::query()->where('employee_id', $employee->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['contracts' => ['راجع العقود الفعالة قبل إعادة الخدمة.']]);
            }
            $periods = EmployeeServicePeriod::query()->where('employee_id', $employee->id)->orderBy('start_date')->get();
            if ($periods->isNotEmpty() && $periods->last()->end_date?->format('Y-m-d') !== $lastEnd) {
                throw ValidationException::withMessages(['employee' => ['تاريخ الإنهاء لا يطابق آخر فترة خدمة؛ راجع السجل.']]);
            }
            if ($periods->contains(fn ($period) => $period->end_date === null
                || $period->end_date->format('Y-m-d') >= $data['effective_date'])) {
                throw ValidationException::withMessages(['effective_date' => ['توجد فترة خدمة مفتوحة أو متداخلة؛ راجع السجل قبل العودة.']]);
            }
            if ($periods->isEmpty()) {
                EmployeeServicePeriod::create(['employee_id' => $employee->id,
                    'start_date' => $employee->hire_date->format('Y-m-d'), 'end_date' => $lastEnd]);
            }
            if (! EmployeeJobHistory::query()->where('employee_id', $employee->id)
                ->whereIn('event_type', ['employment_terminated', 'employment_end_recorded'])
                ->whereDate('effective_date', $lastEnd)->exists()) {
                // Preserve known legacy facts before clearing the current termination fields.
                // This is a captured previous decision, not a newly executed termination.
                EmployeeJobHistory::create(['employee_id' => $employee->id,
                    'event_type' => 'employment_end_recorded', 'effective_date' => $lastEnd,
                    'reason' => $employee->termination_reason, 'recorded_by_user_id' => null]);
            }
            $cutoff = $employee->termination_date->copy()->addDay()->format('Y-m-d');
            // Retire stale PIN ownership without removing device users, stored prints or old punches.
            AttendanceBinding::query()->where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('revoked_from')->orWhere('revoked_from', '>', $cutoff))
                ->update(['revoked_from' => $cutoff]);
            EmployeeServicePeriod::create(['employee_id' => $employee->id, 'start_date' => $data['effective_date']]);
            $employee->update(['status' => 'active', 'termination_date' => null, 'termination_reason' => null]);
            EmployeeJobHistory::create(['employee_id' => $employee->id, 'event_type' => 'employment_rehired',
                'effective_date' => $data['effective_date'], 'reason' => trim($data['reason']), 'recorded_by_user_id' => $actor->id]);
            $this->audit->log('employee.rehired', $employee,
                ['status' => 'terminated', 'termination_date' => $lastEnd],
                ['status' => 'active', 'effective_date' => $data['effective_date']], $actor->id);

            // Login status, roles, contracts and device registration are deliberately separate decisions.
            return $employee->refresh();
        }, 3);
    }
}
