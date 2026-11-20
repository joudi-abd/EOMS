<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\EmployeeComponent;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\SalaryComponentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Salary components (finance settings) and the recurring components HR gives employees. */
class SalaryComponentController extends Controller
{
    public function __construct(private SalaryComponentService $components) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        return ApiResponse::ok($this->components->list($request->has('active') ? $request->boolean('active') : null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:pay_salary_components,code'],
            ...$this->componentRules(true),
        ]);

        return ApiResponse::created($this->components->create($data, $request->user()));
    }

    public function update(SalaryComponent $component, Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['sometimes', 'string', 'max:30'], ...$this->componentRules(false)]);

        return ApiResponse::ok($this->components->update($component, $data, $request->user()));
    }

    public function employeeComponents(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['sometimes', 'integer']]);

        return ApiResponse::ok($this->components->employeeComponents(isset($data['employee_id']) ? (int) $data['employee_id'] : null));
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'component_id' => ['required', 'integer'], ...$this->assignmentRules(true)]);

        return ApiResponse::created($this->components->assign($data, $request->user()));
    }

    public function updateAssignment(EmployeeComponent $assignment, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->components->updateAssignment($assignment, $request->validate($this->assignmentRules(false)), $request->user()));
    }

    public function removeAssignment(EmployeeComponent $assignment, Request $request): JsonResponse
    {
        $this->components->removeAssignment($assignment, $request->user());

        return ApiResponse::message('تم حذف البند من الموظف.');
    }

    private function componentRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'kind' => [$required, Rule::in(['earning', 'deduction'])],
            'calc_method' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'percentage_base' => ['nullable', Rule::in(['basic_salary', 'project_fee'])],
            'percentage' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'is_project_linked' => ['sometimes', 'boolean'],
            'is_recurring' => ['sometimes', 'boolean'],
            'expense_account_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function assignmentRules(bool $creating): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'percentage' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'project_id' => ['nullable', 'integer'],
            'start_month' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m'],
            'end_month' => ['nullable', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
