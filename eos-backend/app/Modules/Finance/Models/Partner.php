<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company partner and their share (D16). */
class Partner extends Model
{
    protected $table = 'fin_partners';

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['party_id' => 'integer', 'share_pct' => 'decimal:4', 'joined_at' => 'date:Y-m-d', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
