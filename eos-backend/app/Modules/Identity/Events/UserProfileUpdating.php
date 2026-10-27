<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;

class UserProfileUpdating
{
    public function __construct(
        public User $user,
        public array $changes
    ) {}
}
