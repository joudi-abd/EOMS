<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

class UserPolicy
{
    public function assignRoles(User $actor, User $target): bool
    {
        return $actor->hasRole('system_admin');
    }
}