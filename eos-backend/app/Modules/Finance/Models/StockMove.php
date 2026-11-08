<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class StockMove extends Model
{
    protected $table = 'fin_stock_moves';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['item_id' => 'integer', 'date' => 'date:Y-m-d', 'quantity' => 'decimal:3', 'unit_cost' => 'decimal:6', 'quantity_after' => 'decimal:3', 'average_cost_after' => 'decimal:6', 'source_id' => 'integer'];
    }
}
