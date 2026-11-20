<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A currency enabled on a box (R7) and its negative-balance setting (D6). */
class CashBoxCurrency extends Model
{
    protected $table = 'fin_cash_box_currencies';

    protected $guarded = ['id'];

    protected $attributes = ['allow_negative' => false];

    protected function casts(): array
    {
        return ['cash_box_id' => 'integer', 'currency_id' => 'integer', 'allow_negative' => 'boolean', 'negative_limit' => 'decimal:4'];
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }
}
