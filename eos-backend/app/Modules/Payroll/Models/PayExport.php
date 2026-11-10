<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class PayExport extends Model
{
    protected $table = 'pay_exports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pay_run_id' => 'integer', 'line_ids' => 'array', 'rows' => 'array', 'pay_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'count' => 'integer', 'total' => 'decimal:2',
            'created_by' => 'integer', 'confirmed_at' => 'datetime', 'confirmed_by' => 'integer', 'box_id' => 'integer'];
    }
}
