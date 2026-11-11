<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'tsk_comments';

    protected $fillable = ['card_id', 'user_id', 'body', 'mentions', 'edited_at'];

    protected function casts(): array
    {
        return ['card_id' => 'integer', 'user_id' => 'integer', 'mentions' => 'array', 'edited_at' => 'datetime'];
    }
}
