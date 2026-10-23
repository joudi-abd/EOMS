<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        if (($data['parent_id'] ?? null) === $department->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['لا يمكن أن يكون القسم تابعاً لنفسه.'],
            ]);
        }

        $department->update($data);

        return $department->refresh();
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