<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Identity\Models\User;

/**
 * Which branches an account reaches with a permission held at "own" scope. Finance implements it from its
 * user↔branch assignments; keeping the interface here lets Projects scope lists without depending on Finance.
 */
interface ProjectBranchScope
{
    /** @return list<int>|null null = every branch, [] = none */
    public function branchIdsFor(User $user, string $permission): ?array;
}
