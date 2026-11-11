<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SystemNotification;
use App\Modules\Tasks\Models\Card;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** In-app notifications for the tasks module, sent after the transaction commits and never to the actor. */
class TaskNotifier
{
    public function toUsers(array $userIds, string $kind, string $message, array $context = [], ?User $actor = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id !== $actor?->id)));
        if ($ids === []) {
            return;
        }
        DB::afterCommit(function () use ($ids, $kind, $message, $context) {
            $users = User::query()->whereIn('id', $ids)->where('status', 'active')->get();
            Notification::send($users, new SystemNotification($kind, $message, $context));
        });
    }

    public function aboutCard(array $userIds, string $kind, string $message, Card $card, ?User $actor = null): void
    {
        $this->toUsers($userIds, $kind, $message.': '.$card->title, ['board_id' => $card->board_id, 'card_id' => $card->id], $actor);
    }
}
