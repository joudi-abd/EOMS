<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    protected $table = 'hr_attendance_policies';

    protected $fillable = [
        'effective_from', 'timezone', 'working_days', 'start_time', 'end_time',
        'grace_minutes', 'grace_mode', 'late_block_minutes', 'overtime_multiplier',
        'pairing_mode', 'duplicate_seconds', 'finalize_after_minutes',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'working_days' => 'array', 'grace_minutes' => 'integer', 'late_block_minutes' => 'integer', 'overtime_multiplier' => 'decimal:2', 'duplicate_seconds' => 'integer', 'finalize_after_minutes' => 'integer'];
    }
}
