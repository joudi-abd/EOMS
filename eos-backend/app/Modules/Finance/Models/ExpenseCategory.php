<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Expense category tree (D21); leaves used for posting carry their expense account. */
class ExpenseCategory extends Model
{
    protected $table = 'fin_expense_categories';

    protected $guarded = ['id'];

    protected $attributes = ['is_overhead_allocatable' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'account_id' => 'integer', 'is_overhead_allocatable' => 'boolean', 'is_active' => 'boolean', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
