<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\UserBranch;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Contracts\ProjectBranchScope;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branch reach of finance permissions (R6, OP-12). A finance permission held at "all" reaches every branch; held at
 * "own" it reaches the branches assigned to the account in fin_user_branches. For `finance.projects.view` and
 * `finance.site_requests`, "own" means projects: the ones the account manages, or supervises (site requests).
 * Lists filter by it; creating or approving outside the reach is refused (403).
 */
class FinanceScope implements ProjectBranchScope
{
    // ProjectService is resolved on use: it depends on this class through ProjectBranchScope.
    public function __construct(private Access $access, private AuditLogger $audit) {}

    /** @return list<int>|null null = every branch, [] = none */
    public function branchIds(User $user, string $permission): ?array
    {
        $scope = $this->access->scope($user, $permission);
        if ($scope === null) {
            return [];
        }
        if ($scope === Scope::ALL) {
            return null;
        }

        return $this->assignedBranches($user->id);
    }

    public function branchIdsFor(User $user, string $permission): ?array
    {
        return $this->branchIds($user, $permission);
    }

    public function allows(User $user, string $permission, ?int $branchId): bool
    {
        $allowed = $this->branchIds($user, $permission);

        return $allowed === null || ($branchId !== null && in_array($branchId, $allowed, true));
    }

    public function assertBranch(User $user, string $permission, ?int $branchId): void
    {
        abort_unless($this->allows($user, $permission, $branchId), 403, 'لا تملك صلاحية على هذا الفرع.');
    }

    /** Limits a query to the branches the account reaches with the permission. */
    public function apply(Builder $query, User $user, string $permission, string $column = 'branch_id'): Builder
    {
        $allowed = $this->branchIds($user, $permission);

        return $allowed === null ? $query : $query->whereIn($column, $allowed);
    }

    /**
     * Projects reached with a project-scoped permission: null = every project. `finance.site_requests` at own =
     * projects the account supervises; `finance.projects.view` at own = projects it manages, plus its branches'
     * projects when it also holds `finance.projects`.
     *
     * @return list<int>|null
     */
    public function projectIds(User $user, string $permission): ?array
    {
        $scope = $this->access->scope($user, $permission);
        if ($scope === null) {
            return [];
        }
        if ($scope === Scope::ALL) {
            return null;
        }
        if ($permission === 'finance.site_requests') {
            return app(ProjectService::class)->supervisedBy($user->id);
        }

        return app(ProjectService::class)->visibleTo($user)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    public function assignedBranches(int $userId): array
    {
        return UserBranch::query()->where('user_id', $userId)->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replaces the branches assigned to an account.
     *
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    public function assign(User $user, array $branchIds, User $actor): array
    {
        return DB::transaction(function () use ($user, $branchIds, $actor) {
            $old = $this->assignedBranches($user->id);
            $new = array_values(array_unique(array_map('intval', $branchIds)));
            sort($new);
            $active = DB::table('sys_branches')->whereIn('id', $new)->where('is_active', true)->count();
            if ($active !== count($new)) {
                throw ValidationException::withMessages(['branch_ids' => ['أحد الفروع غير موجود أو غير فعّال.']]);
            }
            UserBranch::query()->where('user_id', $user->id)->whereNotIn('branch_id', $new)->delete();
            foreach (array_diff($new, $old) as $branchId) {
                UserBranch::create(['user_id' => $user->id, 'branch_id' => $branchId]);
            }
            $this->audit->log('finance.user_branches_set', $user, ['branch_ids' => $old], ['branch_ids' => $new], $actor->id);

            return $new;
        }, 3);
    }
}
