<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bulletin row: how many units of the currency buy one unit of the base currency (USD) on that day. */
class ExchangeRate extends Model
{
    protected $table = 'sys_exchange_rates';

    protected $fillable = ['currency_id', 'rate_date', 'rate_to_base', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['currency_id' => 'integer', 'rate_to_base' => 'decimal:8', 'rate_date' => 'date:Y-m-d', 'created_by' => 'integer'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
