<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** A fiscal month. Created on demand when something posts into it; closed months take no postings (R1). */
class Period extends Model
{
    protected $table = 'fin_periods';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'closed_by' => 'integer', 'closed_at' => 'datetime', 'reopened_by' => 'integer', 'reopened_at' => 'datetime'];
    }
}
