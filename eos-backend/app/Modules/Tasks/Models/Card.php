<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected $table = 'tsk_cards';

    protected $fillable = ['board_id', 'list_id', 'title', 'description', 'priority', 'start_date', 'due_date', 'position', 'previous_list_id', 'done_at', 'done_by', 'completed_at', 'completed_by', 'recurrence_id', 'reminded_soon_for', 'reminded_overdue_for', 'created_by', 'archived_at', 'version'];

    protected function casts(): array
    {
        return ['board_id' => 'integer', 'list_id' => 'integer', 'previous_list_id' => 'integer', 'position' => 'float', 'start_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'done_at' => 'datetime', 'completed_at' => 'datetime', 'archived_at' => 'datetime', 'reminded_soon_for' => 'date:Y-m-d', 'reminded_overdue_for' => 'date:Y-m-d', 'version' => 'integer', 'created_by' => 'integer'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tsk_card_assignees', 'card_id', 'user_id')->withTimestamps();
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tsk_card_watchers', 'card_id', 'user_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'tsk_card_labels', 'card_id', 'label_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }
}
