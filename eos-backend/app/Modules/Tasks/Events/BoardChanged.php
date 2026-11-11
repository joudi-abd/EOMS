<?php

namespace App\Modules\Tasks\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Tells open boards that something changed. It carries only the board's new cursor (the latest activity id) and
 * what happened; clients then fetch the changes they are allowed to see. Sent after the transaction commits, on
 * the private channel tasks.board.{id} (authorised in routes/channels.php).
 */
class BoardChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public int $boardId, public int $cursor, public string $action, public ?int $cardId = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tasks.board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }

    public function broadcastWith(): array
    {
        return ['board_id' => $this->boardId, 'cursor' => $this->cursor, 'action' => $this->action, 'card_id' => $this->cardId];
    }
}
