<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Casts\AttendanceUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class AttendancePunch extends Model
{
    protected $table = 'hr_attendance_punches';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => AttendanceUtcDateTime::class, 'received_at' => AttendanceUtcDateTime::class];
    }
}
