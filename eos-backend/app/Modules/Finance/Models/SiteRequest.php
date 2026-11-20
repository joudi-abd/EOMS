<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A supervisor's site expense request (R24/R25), decided by finance. */
class SiteRequest extends Model
{
    protected $table = 'fin_site_requests';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'project_cost_category_id' => 'integer', 'amount' => 'decimal:4', 'currency_id' => 'integer', 'supplier_party_id' => 'integer', 'custody_id' => 'integer', 'requested_by' => 'integer', 'holder_party_id' => 'integer', 'decided_by' => 'integer', 'decided_at' => 'datetime', 'voucher_id' => 'integer', 'branch_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
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
