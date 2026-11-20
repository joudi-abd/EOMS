<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One role of a party. */
class PartyRole extends Model
{
    protected $table = 'fin_party_roles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['party_id' => 'integer'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
