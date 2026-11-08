<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $table = 'hr_leave_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pay_percent' => 'integer', 'retroactive_days' => 'integer', 'effective_from' => 'date:Y-m-d', 'annual_days' => 'decimal:6'];
    }
}
