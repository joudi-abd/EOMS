<?php

namespace App\Modules\Finance\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Approval requests go to finance.approve holders; decisions go back to whoever recorded the item. Sent after commit. */
class FinanceNotifier
{
    public function toApprovers(string $kind, string $message, array $context, ?int $except = null): void
    {
        $users = User::permission('finance.approve')->where('status', 'active')->get()->reject(fn (User $u) => $u->id === $except);
        $this->send($users, $kind, $message, $context);
    }

    public function toUser(?int $userId, string $kind, string $message, array $context): void
    {
        $this->send(User::query()->whereKey($userId)->where('status', 'active')->get(), $kind, $message, $context);
    }

    private function send($users, string $kind, string $message, array $context): void
    {
        if ($users->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($users, new SystemNotification($kind, $message, $context)));
        }
    }
}
