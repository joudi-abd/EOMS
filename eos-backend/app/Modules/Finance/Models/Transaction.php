<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'fin_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'box_id' => 'integer', 'amount' => 'decimal:2', 'counter_box_id' => 'integer', 'counter_amount' => 'decimal:2', 'rate' => 'decimal:6', 'party_id' => 'integer', 'invoice_id' => 'integer', 'account_id' => 'integer', 'source_id' => 'integer', 'created_by' => 'integer', 'approved_by' => 'integer'];
    }
}
