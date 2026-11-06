<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceBinding extends Model
{
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(AttendanceEnrollment::class, 'binding_id')->latestOfMany();
    }

    protected $table = 'hr_attendance_bindings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
