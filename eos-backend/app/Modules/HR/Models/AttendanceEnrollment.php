<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceEnrollment extends Model
{
    protected $table = 'hr_adms_enrollments';

    protected $guarded = ['id'];
}
