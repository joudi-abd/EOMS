<?php

namespace App\Modules\Finance\Services;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SystemNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** In-system notifications (OP-5) to holders of a finance permission or to one account. Sent after commit. */
class FinanceNotifier
{
    public function __construct(private Access $access) {}

    public function toPermission(string $permission, string $kind, string $message, array $context, ?int $except = null): void
    {
        $this->send($this->access->usersWith($permission)->reject(fn (User $u) => $u->id === $except), $kind, $message, $context);
    }

    public function toUser(?int $userId, string $kind, string $message, array $context): void
    {
        $this->send(User::query()->whereKey($userId)->where('status', 'active')->get(), $kind, $message, $context);
    }

    private function send(Collection $users, string $kind, string $message, array $context): void
    {
        if ($users->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($users, new SystemNotification($kind, $message, $context)));
        }
    }
}
