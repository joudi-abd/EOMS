<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'fin_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revenue_account_id' => 'integer', 'quantity' => 'decimal:3', 'average_cost' => 'decimal:6', 'active' => 'boolean'];
    }
}
