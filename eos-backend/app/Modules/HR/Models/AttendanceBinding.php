<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceBinding extends Model
{
    protected $table = 'hr_attendance_bindings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
