<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmploymentContractService
{
    public function __construct(private AuditLogger $audit) {}

    public function paginate(Employee $employee): LengthAwarePaginator
    {
        return EmploymentContract::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function find(Employee $employee, EmploymentContract $contract): EmploymentContract
    {
        abort_unless((int) $contract->employee_id === (int) $employee->id, 404);

        return $contract;
    }

    public function create(Employee $employee, array $data, ?EmploymentContract $previous = null): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $data, $previous) {
            // Same lock is used by activate/terminate, preventing concurrent lifecycle operations.
            Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($previous !== null) {
                $previous = EmploymentContract::query()->lockForUpdate()->findOrFail($previous->id);
                $this->find($employee, $previous);

                if ($previous->status === 'draft') {
                    throw ValidationException::withMessages(['contract' => ['لا يمكن تجديد عقد لا يزال مسودة.']]);
                }
                if ($previous->renewals()->exists()) {
                    throw ValidationException::withMessages(['contract' => ['تم إنشاء تجديد لهذا العقد مسبقاً.']]);
                }
                if (Carbon::parse($data['start_date'])->lte($previous->start_date)) {
                    throw ValidationException::withMessages(['start_date' => ['يجب أن يبدأ التجديد بعد بداية العقد السابق.']]);
                }
            }

            $contract = EmploymentContract::create([
                ...$data,
                'employee_id' => $employee->id,
                'status' => 'draft',
                'renewed_from_id' => $previous?->id,
                'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null,
            ]);

            $this->audit->log('contract.created', $contract, null, [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
                'renewed_from_id' => $contract->renewed_from_id,
            ]);

            return $contract;
        });
    }

    public function activate(Employee $employee, EmploymentContract $contract): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $contract) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->find($employee, $contract);

            if ($contract->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['لا يمكن اعتماد إلا مسودة العقد.']]);
            }

            $overlap = EmploymentContract::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'active')
                ->whereKeyNot($contract->id)
                ->whereDate('start_date', '<=', $contract->end_date?->format('Y-m-d') ?? '9999-12-31')
                ->where(function ($query) use ($contract) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $contract->start_date->format('Y-m-d'));
                })
                ->where(function ($query) use ($contract) {
                    $query->whereNull('terminated_at')
                        ->orWhereDate('terminated_at', '>=', $contract->start_date->format('Y-m-d'));
                })
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'start_date' => ['توجد فترة متداخلة مع عقد معتمد لهذا الموظف.'],
                ]);
            }

            $contract->update(['status' => 'active']);
            $this->audit->log('contract.activated', $contract, null, [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
            ]);

            return $contract->refresh();
        });
    }

    public function terminate(Employee $employee, EmploymentContract $contract, array $data): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $contract, $data) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->find($employee, $contract);

            $date = Carbon::parse($data['terminated_at'])->startOfDay();
            if ($contract->status !== 'active' || $date->lt($contract->start_date)
                || ($contract->end_date !== null && $date->gt($contract->end_date))) {
                throw ValidationException::withMessages([
                    'terminated_at' => ['تاريخ إنهاء العقد غير صالح لحالته أو فترته.'],
                ]);
            }

            $contract->update([
                'status' => 'terminated',
                'terminated_at' => $date->format('Y-m-d'),
                'termination_reason' => $data['reason'],
                'scheduled_termination_at' => null,
                'scheduled_termination_reason' => null,
            ]);

            $this->audit->log('contract.terminated', $contract, null, [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
            ]);

            return $contract->refresh();
        });
    }

    public function scheduleTermination(Employee $employee, EmploymentContract $contract, array $data): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $contract, $data) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->find($employee, $contract);

            $date = Carbon::parse($data['terminated_at'])->startOfDay();

            if ($contract->status !== 'active'
                || $date->lte(today())
                || $date->lt($contract->start_date)
                || ($contract->end_date !== null && $date->gt($contract->end_date))) {
                throw ValidationException::withMessages([
                    'terminated_at' => ['لا يمكن جدولة الإنهاء خارج فترة عقد فعّال أو بتاريخ غير مستقبلي.'],
                ]);
            }

            if ($contract->scheduled_termination_at !== null) {
                throw ValidationException::withMessages([
                    'terminated_at' => ['يوجد إنهاء مجدول بالفعل. ألغِ الجدولة الحالية أولاً.'],
                ]);
            }

            $contract->update([
                'scheduled_termination_at' => $date->toDateString(),
                'scheduled_termination_reason' => trim($data['reason']),
            ]);

            $this->audit->log('contract.termination_scheduled', $contract, null, [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
                'scheduled_termination_at' => $date->toDateString(),
            ]);

            return $contract->refresh();
        });
    }

    public function cancelScheduledTermination(Employee $employee, EmploymentContract $contract): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $contract) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->find($employee, $contract);

            if ($contract->status !== 'active'
                || $contract->scheduled_termination_at === null
                || $contract->scheduled_termination_at->lte(today())) {
                throw ValidationException::withMessages([
                    'status' => ['لا توجد جدولة مستقبلية قابلة للإلغاء.'],
                ]);
            }

            $plannedDate = $contract->scheduled_termination_at->format('Y-m-d');
            $contract->update([
                'scheduled_termination_at' => null,
                'scheduled_termination_reason' => null,
            ]);

            $this->audit->log('contract.termination_schedule_cancelled', $contract, [
                'scheduled_termination_at' => $plannedDate,
            ], [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
                'scheduled_termination_at' => null,
            ]);

            return $contract->refresh();
        });
    }

    /**
     * Called by the scheduler for a candidate contract. Idempotent under the employee row lock.
     */
    public function executeScheduledTermination(int $contractId): bool
    {
        // Resolve employee id before acquiring a lock; always lock employee before contract.
        $candidate = EmploymentContract::query()->find($contractId);
        if ($candidate === null) {
            return false;
        }

        return DB::transaction(function () use ($contractId, $candidate) {
            Employee::query()->lockForUpdate()->findOrFail($candidate->employee_id);
            $contract = EmploymentContract::query()->lockForUpdate()->find($contractId);

            if ($contract === null || $contract->status !== 'active'
                || $contract->scheduled_termination_at === null
                || $contract->scheduled_termination_at->gt(today())) {
                return false;
            }

            $plannedDate = $contract->scheduled_termination_at->format('Y-m-d');
            $contract->update([
                'status' => 'terminated',
                'terminated_at' => $plannedDate,
                'termination_reason' => $contract->scheduled_termination_reason,
                'scheduled_termination_at' => null,
                'scheduled_termination_reason' => null,
            ]);

            $this->audit->log('contract.terminated', $contract, null, [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'scheduled' => true,
            ]);

            return true;
        });
    }

    /**
     * Expire a contract when its original end date has passed.
     *
     * Returns false if the contract is not eligible or was
     * already processed.
     */
    public function expireContract(int $contractId): bool
    {
        $candidate = EmploymentContract::query()
            ->find($contractId);

        if ($candidate === null) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $contractId) {
            // Keep the same lock order used by other contract operations.
            $employee = Employee::query()
                ->lockForUpdate()
                ->find($candidate->employee_id);

            if ($employee === null) {
                return false;
            }

            $contract = EmploymentContract::query()
                ->lockForUpdate()
                ->find($contractId);

            if (
                $contract === null
                || (int) $contract->employee_id !== (int) $employee->id
                || $contract->status !== 'active'
                || $contract->end_date === null
                || $contract->end_date->gte(today())
            ) {
                return false;
            }

            // A due administrative termination has priority
            // over natural expiration.
            if (
                $contract->scheduled_termination_at !== null
                && $contract->scheduled_termination_at->lte(today())
            ) {
                return false;
            }

            $contract->update([
                'status' => 'expired',
                'scheduled_termination_at' => null,
                'scheduled_termination_reason' => null,
            ]);

            $this->audit->log(
                'contract.expired',
                $contract,
                ['status' => 'active'],
                [
                    'status' => 'expired',
                    'contract_id' => $contract->id,
                    'employee_id' => $employee->id,
                    'end_date' => $contract->end_date->format('Y-m-d'),
                ]
            );

            return true;
        });
    }
}
