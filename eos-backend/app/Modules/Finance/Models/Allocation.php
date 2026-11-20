<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Monthly allocation of payroll or overhead to projects (D13). One approved per kind, month and branch. */
class Allocation extends Model
{
    protected $table = 'fin_allocations';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'branch_id' => 'integer', 'voucher_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AllocationLine::class, 'allocation_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
