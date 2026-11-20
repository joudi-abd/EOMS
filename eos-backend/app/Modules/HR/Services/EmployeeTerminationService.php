<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeJobHistory;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Identity\Services\UserService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTerminationService
{
    public function __construct(
        private UserService $users,
        private AuditLogger $audit
    ) {}

    public function terminate(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($employee->status === 'terminated') {
                throw ValidationException::withMessages([
                    'employee' => ['خدمة الموظف منتهية بالفعل.'],
                ]);
            }
            if ($employee->hire_date && $data['termination_date'] < $employee->hire_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'termination_date' => ['لا يمكن أن يسبق الإنهاء تاريخ التعيين.'],
                ]);
            }
            // Keep contract workflow explicit: settle active/pending contract decisions first.
            if (EmploymentContract::query()->where('employee_id', $employee->id)
                ->where('status', 'active')->exists()) {
                throw ValidationException::withMessages([
                    'contracts' => ['يجب إنهاء العقود الفعالة قبل إنهاء خدمة الموظف.'],
                ]);
            }
            // Service cannot end before a contract's paid coverage ends (terminated or expired contracts still priced
            // those days), or a later rehire could overlap the old contract.
            $coveredUntil = EmploymentContract::query()->where('employee_id', $employee->id)
                ->whereIn('status', ['terminated', 'expired'])->get(['end_date', 'terminated_at'])
                ->map(fn ($c) => ($c->terminated_at ?? $c->end_date)?->format('Y-m-d'))->filter()->max();
            if ($coveredUntil !== null && $data['termination_date'] < $coveredUntil) {
                throw ValidationException::withMessages([
                    'termination_date' => ["تاريخ إنهاء الخدمة يسبق نهاية عقد سابق ({$coveredUntil})."],
                ]);
            }
            // Cross-module guards (e.g. open Sham Cash reservation) listen and may abort.
            event(new EmployeeTerminating($employee));

            app(EmploymentPeriodService::class)->close($employee, $data['termination_date']);
            app(LeaveWorkflowService::class)->serviceEnded($employee, $data['termination_date'], $actor->id);
            $cutoff = Carbon::parse($data['termination_date'])->addDay()->toDateString();
            AttendanceBinding::query()->where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('revoked_from')->orWhere('revoked_from', '>', $cutoff))
                ->update(['revoked_from' => $cutoff]);

            if ($employee->user_id !== null) {
                $linkedUser = User::query()->findOrFail($employee->user_id);
                $this->users->setStatus($linkedUser, 'disabled', $actor, checkReach: false);
            }

            // A person who left manages nothing: department-manager grants must not come back silently on rehire.
            $managed = Department::query()->where('manager_employee_id', $employee->id)->lockForUpdate()->pluck('id');
            if ($managed->isNotEmpty()) {
                Department::query()->whereIn('id', $managed)->update(['manager_employee_id' => null]);
                $this->audit->log('department.manager_cleared', $employee, ['department_ids' => $managed->all()], null);
                app(Access::class)->flush();
            }

            $employee->update([
                'status' => 'terminated',
                'termination_date' => $data['termination_date'],
                'termination_reason' => $data['reason'],
            ]);

            EmployeeJobHistory::create([
                'employee_id' => $employee->id, 'event_type' => 'employment_terminated',
                'effective_date' => $data['termination_date'], 'reason' => $data['reason'],
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->audit->log('employee.terminated', $employee, null, [
                'employee_id' => $employee->id,
                'termination_date' => $data['termination_date'],
            ]);

            return $employee->refresh();
        }, 3);
    }
}
