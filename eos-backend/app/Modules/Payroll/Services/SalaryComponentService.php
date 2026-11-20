<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Finance\Services\PayrollFinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Payroll\Models\EmployeeComponent;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salary components (D19, spec §5.9) and the recurring ones given to employees. Finance manages the components
 * (finance settings); HR assigns recurring ones. System components (the calculator's kinds) keep their code, kind and
 * method: only their name and expense account change. A project-linked component never goes without a project (R21).
 */
class SalaryComponentService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit, private PayrollFinanceService $finance, private ProjectService $projects) {}

    /** @return Collection<int, SalaryComponent> */
    public function list(?bool $active = null): Collection
    {
        return SalaryComponent::query()->when($active !== null, fn ($q) => $q->where('is_active', $active))
            ->orderBy('sort')->orderBy('id')->get();
    }

    public function create(array $data, User $actor): SalaryComponent
    {
        return DB::transaction(function () use ($data, $actor) {
            $attributes = $this->checked($data, null);
            $component = SalaryComponent::create([...$attributes, 'code' => $data['code'], 'is_system' => false,
                'sort' => (int) SalaryComponent::query()->max('sort') + 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('payroll.component_created', $component, null, $component->getAttributes(), $actor->id);

            return $component->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(SalaryComponent $component, array $data, User $actor): SalaryComponent
    {
        return DB::transaction(function () use ($component, $data, $actor) {
            $component = SalaryComponent::query()->lockForUpdate()->findOrFail($component->id);
            if (array_key_exists('code', $data) && $data['code'] !== $component->code) {
                throw ValidationException::withMessages(['code' => ['رمز البند لا يتغيّر بعد إنشائه.']]);
            }
            $old = $component->getAttributes();
            if ($component->is_system) {
                $changed = array_filter([
                    ...array_map(fn (string $f) => array_key_exists($f, $data) && (string) $data[$f] !== (string) $component->{$f} ? $f : null,
                        ['kind', 'calc_method', 'percentage_base']),
                    ...array_map(fn (string $f) => array_key_exists($f, $data) && (bool) $data[$f] !== (bool) $component->{$f} ? $f : null,
                        ['is_project_linked', 'is_recurring', 'is_active']),
                    isset($data['percentage']) ? 'percentage' : null,
                ]);
                if ($changed !== []) {
                    throw ValidationException::withMessages([reset($changed) => ['بنود النظام يتغيّر اسمها وحسابها فقط.']]);
                }
                if (array_key_exists('expense_account_id', $data) && $data['expense_account_id'] !== null) {
                    $this->finance->assertComponentAccount((int) $data['expense_account_id'], $component->kind);
                }
                $component->update([...array_intersect_key($data, array_flip(['name', 'expense_account_id'])), 'updated_by' => $actor->id]);
            } else {
                $attributes = $this->checked([...$component->only(['name', 'kind', 'calc_method', 'percentage_base', 'percentage', 'is_project_linked',
                    'is_recurring', 'expense_account_id', 'is_active']), ...$data], $component);
                $component->update([...$attributes, 'updated_by' => $actor->id]);
            }
            $this->audit->log('payroll.component_updated', $component, $old, $component->getAttributes(), $actor->id);

            return $component->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** @return Collection<int, EmployeeComponent> */
    public function employeeComponents(?int $employeeId): Collection
    {
        return EmployeeComponent::query()->with('component:id,code,name,kind,calc_method,percentage_base,is_project_linked')
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('employee_id')->orderBy('start_month')->orderBy('id')->get();
    }

    public function assign(array $data, User $actor): EmployeeComponent
    {
        return DB::transaction(function () use ($data, $actor) {
            $assignment = EmployeeComponent::create([...$this->checkedAssignment($data), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('payroll.employee_component_added', $assignment, null, $assignment->getAttributes(), $actor->id);

            return $assignment->load('component:id,code,name,kind,calc_method,percentage_base,is_project_linked');
        }, self::TX_ATTEMPTS);
    }

    public function updateAssignment(EmployeeComponent $assignment, array $data, User $actor): EmployeeComponent
    {
        return DB::transaction(function () use ($assignment, $data, $actor) {
            $assignment = EmployeeComponent::query()->lockForUpdate()->findOrFail($assignment->id);
            $old = $assignment->getAttributes();
            $current = $assignment->only(['employee_id', 'component_id', 'amount', 'percentage', 'project_id', 'start_month', 'end_month', 'notes']);
            $assignment->update([...$this->checkedAssignment([...$current, ...$data]), 'updated_by' => $actor->id]);
            $this->audit->log('payroll.employee_component_updated', $assignment, $old, $assignment->getAttributes(), $actor->id);

            return $assignment->refresh()->load('component:id,code,name,kind,calc_method,percentage_base,is_project_linked');
        }, self::TX_ATTEMPTS);
    }

    /** Runs already calculated keep their items; the next calculation no longer adds it. */
    public function removeAssignment(EmployeeComponent $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor) {
            $assignment = EmployeeComponent::query()->lockForUpdate()->findOrFail($assignment->id);
            $this->audit->log('payroll.employee_component_removed', $assignment, $assignment->getAttributes(), null, $actor->id);
            $assignment->delete();
        }, self::TX_ATTEMPTS);
    }

    /**
     * R21 for one item or assignment: a project-linked component needs an existing, open project; any other component
     * takes none.
     */
    public function assertProject(SalaryComponent $component, ?int $projectId, string $field = 'project_id'): void
    {
        if (! $component->is_project_linked) {
            if ($projectId !== null) {
                throw ValidationException::withMessages([$field => ['البند "'.$component->name.'" غير مرتبط بمشروع؛ لا تحدّد مشروعاً.']]);
            }

            return;
        }
        if ($projectId === null) {
            throw ValidationException::withMessages([$field => ['البند "'.$component->name.'" مرتبط بمشروع؛ اختر المشروع.']]);
        }
        $project = $this->projects->find($projectId);
        if ($project === null || $project->status === 'closed') {
            throw ValidationException::withMessages([$field => ['المشروع غير موجود أو مغلق.']]);
        }
    }

    /** Validated component columns (create, or update of a non-system component). */
    private function checked(array $data, ?SalaryComponent $component): array
    {
        $method = $data['calc_method'] ?? 'fixed';
        $base = $method === 'percentage' ? ($data['percentage_base'] ?? null) : null;
        if ($method === 'percentage' && $base === null) {
            throw ValidationException::withMessages(['percentage_base' => ['حدّد أساس النسبة: الراتب الأساسي أو أتعاب المشروع.']]);
        }
        $linked = (bool) ($data['is_project_linked'] ?? false);
        if ($base === 'project_fee' && ! $linked) {
            throw ValidationException::withMessages(['is_project_linked' => ['النسبة من أتعاب مشروع تجعل البند مرتبطاً بمشروع.']]);
        }
        if ($base === 'project_fee' && ($data['is_recurring'] ?? false)) {
            throw ValidationException::withMessages(['is_recurring' => ['أتعاب المشروع تتغيّر كل شهر؛ يُدخل هذا البند يدوياً في الكشف.']]);
        }
        $kind = $data['kind'];
        if ($component !== null && $kind !== $component->kind && EmployeeComponent::query()->where('component_id', $component->id)->exists()) {
            throw ValidationException::withMessages(['kind' => ['البند مسند لموظفين؛ لا يتغيّر نوعه.']]);
        }
        if (! empty($data['expense_account_id'])) {
            $this->finance->assertComponentAccount((int) $data['expense_account_id'], $kind);
        }

        return ['name' => $data['name'], 'kind' => $kind, 'calc_method' => $method, 'percentage_base' => $base,
            'percentage' => $method === 'percentage' && isset($data['percentage']) ? $data['percentage'] : null,
            'is_project_linked' => $linked, 'is_recurring' => (bool) ($data['is_recurring'] ?? false),
            'expense_account_id' => empty($data['expense_account_id']) ? null : (int) $data['expense_account_id'],
            'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    /** Validated assignment columns. */
    private function checkedAssignment(array $data): array
    {
        if (! Employee::query()->whereKey($data['employee_id'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => ['الموظف غير موجود.']]);
        }
        $component = SalaryComponent::query()->find($data['component_id']);
        if ($component === null || ! $component->is_active || $component->is_system || ! $component->is_recurring) {
            throw ValidationException::withMessages(['component_id' => ['اختر بنداً متكرراً فعّالاً من بنود الراتب.']]);
        }
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $this->assertProject($component, $projectId);
        if (! empty($data['end_month']) && $data['end_month'] < $data['start_month']) {
            throw ValidationException::withMessages(['end_month' => ['شهر النهاية لا يسبق شهر البداية.']]);
        }
        $amount = null;
        $percentage = null;
        if ($component->calc_method === 'percentage') {
            $percentage = $data['percentage'] ?? $component->percentage;
            if ($percentage === null || (float) $percentage <= 0 || (float) $percentage > 100) {
                throw ValidationException::withMessages(['percentage' => ['أدخل نسبة بين 0 و100 من الراتب الأساسي.']]);
            }
        } else {
            $amount = $data['amount'] ?? null;
            if ($amount === null || (float) $amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['أدخل مبلغ البند الشهري بعملة عقد الموظف.']]);
            }
        }

        return ['employee_id' => (int) $data['employee_id'], 'component_id' => $component->id, 'amount' => $amount, 'percentage' => $percentage,
            'project_id' => $projectId, 'start_month' => $data['start_month'], 'end_month' => $data['end_month'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
