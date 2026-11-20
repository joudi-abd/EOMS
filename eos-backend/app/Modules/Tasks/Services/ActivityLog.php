<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Events\BoardChanged;
use App\Modules\Tasks\Models\Activity;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use Illuminate\Support\Facades\DB;

/** Card history and the board's change feed in one table; every write also tells open boards to refresh. */
class ActivityLog
{
    public function record(Board|int $board, ?Card $card, ?User $actor, string $action, array $data = []): Activity
    {
        $boardId = $board instanceof Board ? $board->id : $board;
        $activity = Activity::create(['board_id' => $boardId, 'card_id' => $card?->id, 'user_id' => $actor?->id,
            'action' => $action, 'data' => $data === [] ? null : $data, 'created_at' => now()]);
        // Sent once the write commits. A realtime server that is down must not turn a saved change into an error
        // (the client would retry and duplicate it): the failure is reported and clients catch up through /changes.
        $event = new BoardChanged($boardId, $activity->id, $action, $card?->id);
        DB::afterCommit(fn () => rescue(fn () => event($event), report: true));

        return $activity;
    }

    public function cursor(int $boardId): int
    {
        return (int) Activity::query()->where('board_id', $boardId)->max('id');
    }
}
