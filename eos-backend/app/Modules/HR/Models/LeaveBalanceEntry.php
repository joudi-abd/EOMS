<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveBalanceEntry extends Model
{
    protected $table = 'hr_leave_balance_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cycle_start' => 'date:Y-m-d', 'day_units' => 'integer'];
    }
}
