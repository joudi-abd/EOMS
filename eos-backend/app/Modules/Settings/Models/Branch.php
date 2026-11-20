<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $table = 'sys_branches';

    protected $fillable = ['name', 'code', 'is_head_office', 'is_active'];

    protected $attributes = ['is_head_office' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'is_active' => 'boolean'];
    }
}
