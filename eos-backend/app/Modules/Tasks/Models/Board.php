<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $table = 'tsk_boards';

    protected $fillable = ['type', 'name', 'description', 'color', 'department_id', 'project_id', 'created_by', 'archived_at', 'version'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'version' => 'integer', 'department_id' => 'integer', 'created_by' => 'integer'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class);
    }

    public function lists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('position');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
