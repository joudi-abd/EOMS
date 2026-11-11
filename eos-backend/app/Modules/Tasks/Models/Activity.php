<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'tsk_activities';

    protected $fillable = ['board_id', 'card_id', 'user_id', 'action', 'data', 'created_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['board_id' => 'integer', 'card_id' => 'integer', 'user_id' => 'integer', 'data' => 'array', 'created_at' => 'datetime'];
    }
}
