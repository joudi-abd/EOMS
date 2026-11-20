<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** An employee's share of time on a project in a month (payroll allocation). */
class PayrollTimeShare extends Model
{
    protected $table = 'fin_payroll_time_shares';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'employee_id' => 'integer', 'project_id' => 'integer', 'percentage' => 'decimal:4', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }
}
