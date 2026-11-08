<?php

namespace App\Modules\HR\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notification: a stable kind for the frontend, an Arabic message, and ids to link to. */
class HrNotification extends Notification
{
    public function __construct(public string $kind, public string $message, public array $context = []) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'message' => $this->message, ...$this->context];
    }
}
