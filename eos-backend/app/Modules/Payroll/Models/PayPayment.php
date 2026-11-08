<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class PayPayment extends Model
{
    protected $table = 'pay_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pay_run_line_id' => 'integer', 'net_amount' => 'decimal:2', 'exchange_rate' => 'decimal:6',
            'rate_date' => 'date:Y-m-d', 'converted_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'rounding_difference' => 'decimal:2', 'paid_at' => 'date:Y-m-d', 'paid_by' => 'integer', 'finance_transaction_id' => 'integer'];
    }
}
