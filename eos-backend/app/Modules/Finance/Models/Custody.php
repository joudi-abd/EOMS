<?php

namespace App\Modules\Finance\Models;

use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money handed to a person to spend and settle with invoices (D17). Totals are cached from posted vouchers. */
class Custody extends Model
{
    protected $table = 'fin_custodies';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['holder_party_id' => 'integer', 'project_id' => 'integer', 'currency_id' => 'integer', 'branch_id' => 'integer', 'opened_on' => 'date:Y-m-d', 'closed_on' => 'date:Y-m-d', 'issued_total' => 'decimal:4', 'settled_total' => 'decimal:4', 'returned_total' => 'decimal:4', 'balance' => 'decimal:4', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'holder_party_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
