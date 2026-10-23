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
        $employee->update($data);

        return $employee->refresh();
    }
}