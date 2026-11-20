<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Department;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    public function __construct(private AuditLogger $audit, private Access $access) {}

    public function paginate(): LengthAwarePaginator
    {
        return Department::with('parent')->orderBy('name')->paginate(20);
    }

    public function show(Department $department): Department
    {
        return $department->load('parent');
    }

    public function create(array $data): Department
    {
        return DB::transaction(function () use ($data) {
            $this->ensureMayAppoint($data, null);
            $department = Department::create($data);
            $this->audit->log('department.created', $department, null, $department->getAttributes());
            $this->managersChanged($data);

            return $department;
        }, 3);
    }

    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {

            $department = Department::query()
                ->lockForUpdate()
                ->findOrFail($department->id);

            if (array_key_exists('parent_id', $data)
                && $data['parent_id'] !== null) {

                $this->validateParent(
                    $department,
                    (int) $data['parent_id']
                );
            }
            $this->ensureMayAppoint($data, $department);

            $old = $department->only(array_keys($data));
            $department->update($data);
            $this->audit->log('department.updated', $department, $old, $data);
            $this->managersChanged($data);

            return $department->refresh();
        }, 3);
    }

    /**
     * A department manager receives the department role's grants. HR appoints managers as part of its work, but
     * nobody appoints themselves into grants they do not already hold; the person must be a current employee.
     */
    private function ensureMayAppoint(array $data, ?Department $department): void
    {
        if (! array_key_exists('manager_employee_id', $data) || $data['manager_employee_id'] === null
            || (int) $data['manager_employee_id'] === (int) $department?->manager_employee_id) {
            return;
        }
        $actor = auth()->user();
        $self = DB::table('hr_employees')->where('id', $data['manager_employee_id'])->value('user_id') === $actor?->id;
        if (! $actor instanceof User || ($self && ! $this->access->mayAppointDepartmentManager($actor))) {
            throw ValidationException::withMessages(['manager_employee_id' => [
                'تعيين مدير القسم يمنحه صلاحيات القسم؛ لا تعيّن نفسك مديراً لصلاحيات لا تملكها.',
            ]]);
        }
        $active = DB::table('hr_employees')->where('id', $data['manager_employee_id'])->where('status', '!=', 'terminated')->exists();
        if (! $active) {
            throw ValidationException::withMessages(['manager_employee_id' => ['مدير القسم يجب أن يكون موظفاً على رأس عمله.']]);
        }
    }

    private function managersChanged(array $data): void
    {
        if (array_key_exists('manager_employee_id', $data)) {
            DB::afterCommit(fn () => $this->access->flush());
        }
    }

    private function validateParent(
        Department $department,
        int $parentId
    ): void {
        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null) {

            // Prevent self-reference and descendant cycles
            if ($currentId === (int) $department->id) {
                throw ValidationException::withMessages([
                    'parent_id' => [
                        'لا يمكن جعل القسم تابعاً لنفسه أو لأحد أقسامه الفرعية.',
                    ],
                ]);
            }

            // Detect an existing circular hierarchy
            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => [
                        'توجد علاقة دائرية في الهيكل التنظيمي.',
                    ],
                ]);
            }

            $visited[$currentId] = true;

            $currentId = Department::query()
                ->whereKey($currentId)
                ->value('parent_id');

            $currentId = $currentId === null
                ? null
                : (int) $currentId;
        }
    }

    public function delete(Department $department): void
    {
        DB::transaction(function () use ($department) {
            // Locked so an employee cannot be assigned between the checks and the delete.
            $department = Department::query()->lockForUpdate()->findOrFail($department->id);

            if ($department->employees()->exists()) {
                throw ValidationException::withMessages([
                    'department' => ['لا يمكن حذف قسم فيه موظفون.'],
                ]);
            }

            if ($department->children()->exists()) {
                throw ValidationException::withMessages([
                    'department' => ['لا يمكن حذف قسم له أقسام فرعية.'],
                ]);
            }

            $this->audit->log('department.deleted', $department, $department->getAttributes(), null);
            $department->delete();
            DB::afterCommit(fn () => $this->access->flush());
        }, 3);
    }
}
