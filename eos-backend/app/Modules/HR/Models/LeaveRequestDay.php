<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequestDay extends Model
{
    protected $table = 'hr_leave_request_days';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'leave_request_id' => 'integer', 'pay_percent' => 'integer', 'minutes' => 'integer', 'day_minutes' => 'integer', 'schedule' => 'array', 'active' => 'boolean', 'day_units' => 'integer', 'covered_units' => 'integer', 'unpaid_units' => 'integer'];
    }
}
