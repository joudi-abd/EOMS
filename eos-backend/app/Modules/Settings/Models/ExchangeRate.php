<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $table = 'sys_exchange_rates';

    protected $fillable = ['from_currency_id', 'to_currency_id', 'rate', 'rate_date'];

    protected $casts = [
        'rate' => 'decimal:6',
        'rate_date' => 'date',
    ];
}
