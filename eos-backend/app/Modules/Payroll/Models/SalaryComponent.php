<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A salary component (D19): earning or deduction, fixed or a percentage (of basic pay or of a project fee), linked to a
 * project or not, recurring or one-time, with an optional finance expense account (else `salaries_expense`).
 * System components are the calculator's own kinds; their code is the pay run item kind.
 */
class SalaryComponent extends Model
{
    /** Kinds HR may enter by hand without choosing another component. */
    public const MANUAL_SYSTEM = ['bonus', 'manual_deduction'];

    protected $table = 'pay_salary_components';

    protected $guarded = ['id'];

    protected $attributes = ['calc_method' => 'fixed', 'is_project_linked' => false, 'is_recurring' => false, 'is_system' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:4', 'is_project_linked' => 'boolean', 'is_recurring' => 'boolean', 'expense_account_id' => 'integer',
            'is_system' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }
}
