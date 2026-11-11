<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardMember extends Model
{
    protected $table = 'tsk_board_members';

    protected $fillable = ['board_id', 'user_id', 'role', 'added_by'];

    protected function casts(): array
    {
        return ['board_id' => 'integer', 'user_id' => 'integer'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
