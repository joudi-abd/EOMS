<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeeService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $account = $data['account'];
            $nationalId = (string) $data['national_id'];
            $nationalIdHash = hash('sha256', $nationalId);

            if (Employee::where('national_id_hash', $nationalIdHash)->exists()) {
                throw ValidationException::withMessages([
                    'national_id' => ['الرقم الوطني مسجّل مسبقاً لموظف آخر.'],
                ]);
            }

            // حساب الدخول والدور الافتراضي في المعاملة نفسها
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

            $this->audit->log('employee.created', $employee, null, $employee->toArray());

            return $employee;
        });
    }

    /**
     * الرقم الوظيفي بصيغة U-XXXXXXXX، ويُتحقق من عدم تكراره.
     */
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
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(! empty($filters['job_title_id']), fn ($q) => $q->where('job_title_id', $filters['job_title_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('first_name')
            ->paginate(20);
    }

    
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {

            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->id);

            if (array_key_exists('manager_id', $data)
                && $data['manager_id'] !== null) {

                $this->validateManager(
                    $employee,
                    (int) $data['manager_id']
                );
            }

            $employee->fill($data);

            if (! $employee->isDirty()) {
                return $employee;
            }

            // Only non-sensitive organizational fields
            // are included in audit details.
            $auditableFields = [
                'department_id',
                'job_title_id',
                'manager_id',
            ];

            $changes = array_intersect_key(
                $employee->getDirty(),
                array_flip($auditableFields)
            );

            $oldValues = [];
            $newValues = [];

            foreach ($changes as $field => $value) {
                $oldValues[$field] = $employee->getRawOriginal($field);
                $newValues[$field] = $value;
            }

            $employee->save();

            $this->audit->log(
                'employee.updated',
                $employee,
                $oldValues ?: null,
                $newValues ?: null
            );

            return $employee->refresh();
        });
    }

    /**
     * Prevent self-management and circular
     * reporting relationships.
     */
    private function validateManager(
        Employee $employee,
        int $managerId
    ): void {
        $visited = [];
        $currentId = $managerId;

        while ($currentId !== null) {

            if ($currentId === (int) $employee->id) {
                throw ValidationException::withMessages([
                    'manager_id' => [
                        'لا يمكن إنشاء علاقة إدارية دائرية.',
                    ],
                ]);
            }

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'manager_id' => [
                        'توجد علاقة إدارية دائرية مسبقاً.',
                    ],
                ]);
            }

            $visited[$currentId] = true;

            $currentId = Employee::query()
                ->whereKey($currentId)
                ->value('manager_id');

            $currentId = $currentId === null
                ? null
                : (int) $currentId;
        }
    }

}