<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCycle extends Model
{
    protected $table = 'hr_leave_cycles';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['policy' => 'array', 'manual' => 'boolean', 'annual_units' => 'integer', 'granted_units' => 'integer'];
    }
}
