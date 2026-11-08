<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notification any module can send: a stable kind, an Arabic message and ids to link to. */
class SystemNotification extends Notification
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
