<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\HR\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * The employees who hold assets (spec §5.8 current_holder_employee_id). Reads HR employees by id, which the module
 * boundaries tolerate inside a service; nothing is written to HR.
 */
class AssetHolders
{
    /** An employee who may receive an asset: exists and is not terminated. Null passes (no holder). */
    public function assertAssignable(?int $employeeId, string $field = 'holder_employee_id'): ?Employee
    {
        if ($employeeId === null) {
            return null;
        }
        $employee = Employee::query()->find($employeeId);
        if ($employee === null || $employee->status === 'terminated') {
            throw ValidationException::withMessages([$field => ['الموظف غير موجود أو منتهية خدمته.']]);
        }

        return $employee;
    }

    /**
     * Display data of several employees at once.
     *
     * @param  iterable<int|null>  $ids
     * @return array<int, array{id: int, name: string, employee_no: string|null}>
     */
    public function describe(iterable $ids): array
    {
        $ids = array_values(array_unique(array_filter(is_array($ids) ? $ids : iterator_to_array($ids))));
        if ($ids === []) {
            return [];
        }

        return Employee::query()->whereIn('id', $ids)->get(['id', 'employee_no', 'first_name', 'father_name', 'last_name'])
            ->mapWithKeys(fn (Employee $e) => [$e->id => ['id' => $e->id, 'name' => trim($e->full_name), 'employee_no' => $e->employee_no]])
            ->all();
    }

    /** Finds an employee by number or by full name ("first last"), for the asset sheet import. */
    public function findByLabel(string $label): ?int
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $byNumber = Employee::query()->where('employee_no', $label)->where('status', '!=', 'terminated')->value('id');
        if ($byNumber !== null) {
            return (int) $byNumber;
        }
        $matches = Employee::query()->where('status', '!=', 'terminated')->get(['id', 'first_name', 'father_name', 'last_name'])
            ->filter(fn (Employee $e) => in_array($this->normalise($label), [$this->normalise($e->first_name.' '.$e->last_name),
                $this->normalise($e->full_name)], true));

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }
}
