<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sale, scrapping or loss of an asset. */
class AssetDisposal extends Model
{
    protected $table = 'fin_asset_disposals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['asset_id' => 'integer', 'date' => 'date:Y-m-d', 'sale_amount' => 'decimal:4', 'currency_id' => 'integer', 'voucher_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
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
