<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayRunItem extends Model
{
    protected $table = 'pay_run_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pay_run_line_id' => 'integer', 'quantity' => 'decimal:4', 'proposed_amount' => 'decimal:2',
            'amount' => 'decimal:2', 'details' => 'array', 'created_by' => 'integer', 'decided_by' => 'integer',
            'decided_at' => 'datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }
}
