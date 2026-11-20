<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A company asset (D22, no depreciation): book value is its cost until disposal. */
class Asset extends Model
{
    public const STATUSES = ['in_service', 'under_maintenance', 'sold', 'scrapped', 'lost'];

    protected $table = 'fin_assets';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'in_service'];

    protected function casts(): array
    {
        return ['category_id' => 'integer', 'purchase_date' => 'date:Y-m-d', 'quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:4', 'currency_id' => 'integer', 'purchase_voucher_id' => 'integer', 'current_holder_employee_id' => 'integer', 'branch_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function custodyHistory(): HasMany
    {
        return $this->hasMany(AssetCustodyHistory::class, 'asset_id');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class, 'asset_id');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class, 'asset_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
