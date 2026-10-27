<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $account = $data['account'];
            $nationalId = trim((string) $data['national_id']);
            $nationalIdHash = hash('sha256', $nationalId);

            if (Employee::where('national_id_hash', $nationalIdHash)->exists()) {
                throw ValidationException::withMessages([
                    'national_id' => ['الرقم الوطني مسجّل مسبقاً لموظف آخر.'],
                ]);
            }

            $user = User::create([
                'name' => $data['first_name'].' '.$data['last_name'],
                'email' => $account['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $account['password'],
                'status' => 'active',
            ]);
            $user->assignRole('employee');

            $employee = Employee::create(array_merge(
                Arr::except($data, ['account', 'national_id']),
                [
                    'employee_no' => $this->generateEmployeeNo(),
                    'user_id' => $user->id,
                    'national_id' => $nationalId,
                    'national_id_hash' => $nationalIdHash,
                    'status' => 'active',
                ]
            ));

            // Never include address, ID, birthdate or wallet details in audit logs.
            $this->audit->log('employee.created', $employee, null, [
                'employee_id' => $employee->id,
                'employee_no' => $employee->employee_no,
            ]);

            return $employee;
        });
    }

    private function generateEmployeeNo(): string
    {
        do {
            $no = 'U-'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (Employee::where('employee_no', $no)->exists());

        return $no;
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return Employee::query()
            ->with(['department:id,name', 'jobTitle:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('employee_no', 'like', "%{$search}%")
                ->when(auth()->user()?->can('employees.view_sensitive'),
                    fn ($w) => $w->orWhere('phone', 'like', "%{$search}%"))))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(! empty($filters['job_title_id']), fn ($q) => $q->where('job_title_id', $filters['job_title_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('first_name')
            ->paginate(20);
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (array_key_exists('manager_id', $data) && $data['manager_id'] !== null) {
                $this->validateManager($employee, (int) $data['manager_id']);
            }

            $employee->fill($data);

            if (! $employee->isDirty()) {
                return $employee;
            }

            $tracked = ['department_id', 'job_title_id', 'manager_id'];
            $changes = Arr::only($employee->getDirty(), $tracked);
            $oldValues = Arr::only($employee->getRawOriginal(), array_keys($changes));

            $employee->save();

            // Employee identity and the login account should share name and phone.
            if ($employee->user_id !== null && (
                array_key_exists('first_name', $data)
                || array_key_exists('last_name', $data)
                || array_key_exists('phone', $data)
            )) {
                $user = User::query()->lockForUpdate()->findOrFail($employee->user_id);
                $user->name = $employee->full_name;

                if (array_key_exists('phone', $data)) {
                    $user->phone = $employee->phone;
                }

                $user->save();
            }

            $this->audit->log(
                'employee.updated',
                $employee,
                $oldValues ?: null,
                $changes ?: null
            );

            return $employee->refresh();
        });
    }

    private function validateManager(Employee $employee, int $managerId): void
    {
        $visited = [];
        $currentId = $managerId;

        while ($currentId !== null) {
            if ($currentId === (int) $employee->id) {
                throw ValidationException::withMessages([
                    'manager_id' => ['لا يمكن إنشاء علاقة إدارية دائرية.'],
                ]);
            }

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'manager_id' => ['توجد علاقة إدارية دائرية مسبقاً.'],
                ]);
            }

            $visited[$currentId] = true;
            $currentId = Employee::query()->whereKey($currentId)->value('manager_id');
            $currentId = $currentId === null ? null : (int) $currentId;
        }
    }
}
