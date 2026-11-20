<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recurring component an employee receives every month from `start_month` to `end_month` (open-ended when null). */
class EmployeeComponent extends Model
{
    protected $table = 'pay_employee_components';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'component_id' => 'integer', 'amount' => 'decimal:4', 'percentage' => 'decimal:4', 'project_id' => 'integer',
            'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'component_id');
    }
}
