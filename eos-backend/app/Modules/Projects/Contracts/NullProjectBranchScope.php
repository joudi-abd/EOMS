<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Identity\Models\User;

/** Used until a module that knows branch assignments binds its own implementation: "own" reaches no branch. */
class NullProjectBranchScope implements ProjectBranchScope
{
    public function branchIdsFor(User $user, string $permission): ?array
    {
        return [];
    }
}
