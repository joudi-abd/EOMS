<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Running balance (debit − credit) per account, currency and party; rebuilt by finance:rebuild-balances. */
class AccountBalance extends Model
{
    public const CREATED_AT = null;

    protected $table = 'fin_account_balances';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'currency_id' => 'integer', 'party_key' => 'integer', 'balance' => 'decimal:4', 'updated_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
