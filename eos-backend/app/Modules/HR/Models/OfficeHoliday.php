<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeHoliday extends Model
{
    protected $table = 'hr_office_holidays';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
