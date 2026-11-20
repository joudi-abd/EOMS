<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which chart account plays a fixed system role (spec §4.3). */
class SystemAccount extends Model
{
    protected $table = 'fin_system_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
