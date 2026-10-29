<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Casts\AttendanceUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    protected $table = 'hr_attendance_devices';

    protected $fillable = [
        'name', 'serial_number', 'timezone', 'model', 'token_hash', 'is_active',
        'last_seen_at', 'complete_through',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => AttendanceUtcDateTime::class, 'complete_through' => AttendanceUtcDateTime::class];
    }
}
