<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Casts\AttendanceUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $table = 'hr_attendance_corrections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_in' => AttendanceUtcDateTime::class, 'last_out' => AttendanceUtcDateTime::class, 'reviewed_at' => AttendanceUtcDateTime::class, 'base_version' => 'integer'];
    }
}
