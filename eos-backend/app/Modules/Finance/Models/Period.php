<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    protected $table = 'fin_periods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'requested_by' => 'integer', 'decided_by' => 'integer'];
    }
}
