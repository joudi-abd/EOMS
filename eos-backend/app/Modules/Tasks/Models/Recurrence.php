<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class Recurrence extends Model
{
    protected $table = 'tsk_recurrences';

    protected $fillable = ['board_id', 'list_id', 'template', 'frequency', 'weekdays', 'month_day', 'due_in_days', 'next_run_on', 'last_run_on', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['template' => 'array', 'weekdays' => 'array', 'month_day' => 'integer', 'due_in_days' => 'integer', 'next_run_on' => 'date:Y-m-d', 'last_run_on' => 'date:Y-m-d', 'active' => 'boolean', 'board_id' => 'integer', 'list_id' => 'integer'];
    }
}
