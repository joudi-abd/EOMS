<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Budget of one project cost category in one currency (D10). */
class ProjectBudgetLine extends Model
{
    protected $table = 'fin_project_budget_lines';

    protected $guarded = ['id'];

    protected $attributes = ['alert_level' => 'none'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'project_cost_category_id' => 'integer', 'currency_id' => 'integer', 'budget_amount' => 'decimal:4', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCostCategory::class, 'project_cost_category_id');
    }
}
