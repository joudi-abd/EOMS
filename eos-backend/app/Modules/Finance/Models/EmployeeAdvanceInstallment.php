<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One monthly installment of an employee advance. */
class EmployeeAdvanceInstallment extends Model
{
    protected $table = 'fin_employee_advance_installments';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['advance_id' => 'integer', 'amount' => 'decimal:4', 'pay_run_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'advance_id');
    }
}
