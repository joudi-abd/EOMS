<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chart of accounts. Only postable, active accounts take journal lines; a parent stops being postable when it gets a child. */
class Account extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    protected $table = 'fin_accounts';

    protected $guarded = ['id'];

    protected $attributes = ['is_postable' => true, 'requires_party' => false, 'requires_project' => false, 'is_system' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'is_postable' => 'boolean', 'requires_party' => 'boolean', 'requires_project' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }
}
