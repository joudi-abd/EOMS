<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A periodic count of a box (D24): the difference posts only after a second person approves (R22). */
class CashCount extends Model
{
    protected $table = 'fin_cash_counts';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['cash_box_id' => 'integer', 'branch_id' => 'integer', 'count_date' => 'date:Y-m-d', 'counted_by' => 'integer', 'submitted_at' => 'datetime', 'snapshot_entry_id' => 'integer', 'approved_by' => 'integer', 'approved_at' => 'datetime', 'voucher_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashCountLine::class, 'cash_count_id');
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
