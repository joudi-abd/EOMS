<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\DepartmentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DepartmentController extends Controller
{
    public function __construct(private DepartmentService $departments) {}

    public function index()
    {
        return ApiResponse::paginated($this->departments->paginate());
    }

    public function show(Department $department)
    {
        return ApiResponse::ok($this->departments->show($department));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:hr_departments,code'],
            'parent_id' => ['nullable', 'integer', 'exists:hr_departments,id'],
        ]);

        return ApiResponse::created($this->departments->create($data));
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:20', 'unique:hr_departments,code,'.$department->id],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:hr_departments,id'],
        ]);

        return ApiResponse::ok($this->departments->update($department, $data));
    }

    public function destroy(Department $department)
    {
        $this->departments->delete($department);

        return ApiResponse::message('تم حذف القسم.');
    }
}
