<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Casts\AttendanceUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    protected $table = 'hr_attendance_days';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'first_in' => AttendanceUtcDateTime::class, 'last_out' => AttendanceUtcDateTime::class, 'approved_at' => AttendanceUtcDateTime::class, 'flags' => 'array', 'coverage_complete' => 'boolean', 'pending_changes' => 'boolean', 'version' => 'integer', 'worked_minutes' => 'integer', 'late_actual_minutes' => 'integer', 'late_minutes' => 'integer', 'early_minutes' => 'integer', 'extra_minutes' => 'integer'];
    }
}
