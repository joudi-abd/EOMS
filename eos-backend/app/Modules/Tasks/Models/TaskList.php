<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskList extends Model
{
    protected $table = 'tsk_lists';

    protected $fillable = ['board_id', 'name', 'kind', 'position', 'archived_at'];

    protected function casts(): array
    {
        return ['board_id' => 'integer', 'position' => 'float', 'archived_at' => 'datetime'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }
}
