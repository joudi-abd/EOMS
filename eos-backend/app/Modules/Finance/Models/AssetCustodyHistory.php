<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who held an asset, and when it moved. */
class AssetCustodyHistory extends Model
{
    protected $table = 'fin_asset_custody_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['asset_id' => 'integer', 'from_employee_id' => 'integer', 'to_employee_id' => 'integer', 'transferred_at' => 'date:Y-m-d', 'recorded_by' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
