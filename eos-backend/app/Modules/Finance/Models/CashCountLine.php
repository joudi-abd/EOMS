<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Counted amount of one currency against the system balance captured at submit. */
class CashCountLine extends Model
{
    protected $table = 'fin_cash_count_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cash_count_id' => 'integer', 'currency_id' => 'integer', 'system_balance' => 'decimal:4', 'counted_amount' => 'decimal:4', 'difference' => 'decimal:4'];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }
}
