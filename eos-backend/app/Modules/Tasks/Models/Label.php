<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class Label extends Model
{
    protected $table = 'tsk_labels';

    protected $fillable = ['board_id', 'name', 'color'];

    protected function casts(): array
    {
        return ['board_id' => 'integer'];
    }
}
