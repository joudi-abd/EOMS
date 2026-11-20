<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One project's share of an allocation. */
class AllocationLine extends Model
{
    protected $table = 'fin_allocation_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocation_id' => 'integer', 'project_id' => 'integer', 'currency_id' => 'integer', 'employee_id' => 'integer', 'percentage' => 'decimal:4', 'amount' => 'decimal:4'];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'allocation_id');
    }
}
