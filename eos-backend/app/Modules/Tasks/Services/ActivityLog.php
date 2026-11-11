<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Events\BoardChanged;
use App\Modules\Tasks\Models\Activity;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;

/** Card history and the board's change feed in one table; every write also tells open boards to refresh. */
class ActivityLog
{
    public function record(Board|int $board, ?Card $card, ?User $actor, string $action, array $data = []): Activity
    {
        $boardId = $board instanceof Board ? $board->id : $board;
        $activity = Activity::create(['board_id' => $boardId, 'card_id' => $card?->id, 'user_id' => $actor?->id,
            'action' => $action, 'data' => $data === [] ? null : $data, 'created_at' => now()]);
        event(new BoardChanged($boardId, $activity->id, $action, $card?->id));

        return $activity;
    }

    public function cursor(int $boardId): int
    {
        return (int) Activity::query()->where('board_id', $boardId)->max('id');
    }
}
