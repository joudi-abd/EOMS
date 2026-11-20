<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    public const ROLES = ['supervisor', 'member'];

    protected $table = 'prj_project_members';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'user_id' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
