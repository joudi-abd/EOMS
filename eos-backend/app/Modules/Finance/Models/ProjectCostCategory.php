<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** How project costs are organised (materials, labour, ...); billable ones go to the client. */
class ProjectCostCategory extends Model
{
    protected $table = 'fin_project_cost_categories';

    protected $guarded = ['id'];

    protected $attributes = ['is_billable' => true, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_billable' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }
}
