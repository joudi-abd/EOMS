<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'closed'];

    protected $table = 'prj_projects';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'planned'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'manager_user_id' => 'integer', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }
}
