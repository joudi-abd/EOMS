<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A place that holds company money in one or more currencies, with its own ledger account. */
class CashBox extends Model
{
    public const TYPES = ['cash_safe', 'exchange_office', 'bank', 'e_wallet'];

    protected $table = 'fin_cash_boxes';

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'account_id' => 'integer', 'custodian_user_id' => 'integer', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(CashBoxCurrency::class, 'cash_box_id');
    }
}
