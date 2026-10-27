<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\DepartmentService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('03 - HR / Departments', weight: 3)]
class DepartmentController extends Controller
{
    public function __construct(private DepartmentService $departments) {}

    /**
     * List departments.
     *
     * Returns a paginated department list.
     * Supports searching and filtering by parent department.
     */
    public function index()
    {
        return ApiResponse::paginated($this->departments->paginate());
    }

    /**
     * Show department details.
     *
     * Returns the department profile with related
     * parent department information.
     */
    public function show(Department $department)
    {
        return ApiResponse::ok($this->departments->show($department));
    }

    /**
     * Create a department.
     *
     * Creates a department record with optional
     * parent department association.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:hr_departments,code'],
            'parent_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
        ]);

        return ApiResponse::created($this->departments->create($data));
    }

    /**
     * Update department information.
     *
     * Updates the department profile and synchronizes
     * parent department association.
     */
    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:20', 'unique:hr_departments,code,'.$department->id],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_departments,id'],
        ]);

        return ApiResponse::ok($this->departments->update($department, $data));
    }

    /**
     * Delete a department.
     *
     * Deletes the department record if it has no
     * child departments or associated employees.
     */
    public function destroy(Department $department)
    {
        $this->departments->delete($department);

        return ApiResponse::message('تم حذف القسم.');
    }
}
