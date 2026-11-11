<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checklist extends Model
{
    protected $table = 'tsk_checklists';

    protected $fillable = ['card_id', 'title', 'position'];

    protected function casts(): array
    {
        return ['card_id' => 'integer', 'position' => 'float'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }
}
