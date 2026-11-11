<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $table = 'tsk_checklist_items';

    protected $fillable = ['checklist_id', 'text', 'done', 'done_by', 'done_at', 'position'];

    protected function casts(): array
    {
        return ['checklist_id' => 'integer', 'done' => 'boolean', 'done_at' => 'datetime', 'position' => 'float'];
    }
}
