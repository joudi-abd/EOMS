<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A maintenance of an asset and the voucher that paid it. */
class AssetMaintenance extends Model
{
    protected $table = 'fin_asset_maintenance';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['asset_id' => 'integer', 'voucher_id' => 'integer', 'date' => 'date:Y-m-d', 'project_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
