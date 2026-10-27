<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
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
        return Department::create($data);
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

            $department->update($data);

            return $department->refresh();
        });
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

        $department->delete();
    }
}
