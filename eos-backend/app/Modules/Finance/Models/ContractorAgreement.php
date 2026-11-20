<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A contractor's agreement on a project (D11, agreement mode). */
class ContractorAgreement extends Model
{
    protected $table = 'fin_contractor_agreements';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'contractor_party_id' => 'integer', 'project_cost_category_id' => 'integer', 'currency_id' => 'integer', 'agreed_value' => 'decimal:4', 'discount' => 'decimal:4', 'net_value' => 'decimal:4', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'contractor_party_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCostCategory::class, 'project_cost_category_id');
    }
}
