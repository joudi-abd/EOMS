<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The finance side of a project: code, client, contract type and fee. */
class ProjectProfile extends Model
{
    public const CONTRACT_TYPES = ['cost_plus', 'percentage_of_receipts', 'lump_sum', 'internal'];

    protected $table = 'fin_project_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'client_party_id' => 'integer', 'contract_currency_id' => 'integer', 'contract_value' => 'decimal:4', 'fee_pct' => 'decimal:4', 'fee_base_category_ids' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_party_id');
    }
}
