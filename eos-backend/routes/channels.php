<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Services\BoardAccess;
use Illuminate\Support\Facades\Broadcast;

// A board's change feed: anyone who can see the board (viewers included) may listen.
Broadcast::channel('tasks.board.{boardId}', function (User $user, string $boardId) {
    // A non-numeric id is simply not authorised (a typed int parameter would throw and answer 500).
    $board = ctype_digit($boardId) ? Board::find((int) $boardId) : null;

    return $board !== null && $user->can('tasks.view') && app(BoardAccess::class)->role($user, $board) !== null;
});
