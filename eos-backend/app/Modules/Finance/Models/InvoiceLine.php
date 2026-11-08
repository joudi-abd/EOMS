<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $table = 'fin_invoice_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['invoice_id' => 'integer', 'item_id' => 'integer', 'account_id' => 'integer', 'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'cost_amount' => 'decimal:2'];
    }

    public $timestamps = false;
}
