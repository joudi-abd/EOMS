<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Asset category tree. */
class AssetCategory extends Model
{
    protected $table = 'fin_asset_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AssetCategory::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
