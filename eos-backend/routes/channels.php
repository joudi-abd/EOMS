<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Services\BoardAccess;
use Illuminate\Support\Facades\Broadcast;

// A board's change feed: anyone who can see the board (viewers included) may listen.
Broadcast::channel('tasks.board.{boardId}', function (User $user, int $boardId) {
    $board = Board::find($boardId);

    return $board !== null && $user->can('tasks.view') && app(BoardAccess::class)->role($user, $board) !== null;
});
