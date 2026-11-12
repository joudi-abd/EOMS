<?php

namespace App\Modules\Identity\Access;

use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "may this account do X, and how far does it reach?".
 *
 * An account's grants are the union of its roles' grants (the widest scope wins), plus — when it manages a
 * department — the department-manager role's grants capped at department scope. The administrator role holds
 * every catalogued permission at its widest scope. Direct per-account grants do not exist.
 *
 * `Gate::before` routes every catalogued ability through here, so `$user->can('x')`, `@can` and the `perm:`
 * middleware all mean "holds x at some scope". Code that needs a reach asks `scope()` or `covers()`.
 */
class Access
{
    /** @var array<int, array<string, string>> */
    private array $memo = [];

    /** @var list<int>|null */
    private ?array $managers = null;

    /** @var array<string, string>|null */
    private ?array $department = null;

    public function __construct(private EmployeeDirectory $directory) {}

    /** @return array<string, string> permission => widest scope held */
    public function grants(User $user): array
    {
        return $this->memo[$user->id] ??= $this->resolve($user);
    }

    public function allows(User $user, string $permission): bool
    {
        return isset($this->grants($user)[$permission]);
    }

    /** The widest scope the account holds the permission at, or null when it does not hold it. */
    public function scope(User $user, string $permission): ?string
    {
        return $this->grants($user)[$permission] ?? null;
    }

    /** True when the account holds the permission at `$scope` or wider. */
    public function covers(User $user, string $permission, string $scope = Scope::ALL): bool
    {
        return Scope::covers($this->scope($user, $permission), $scope);
    }

    public function isAdmin(User $user): bool
    {
        return $this->roleRows($user)->contains(fn ($role) => $role->kind === 'admin');
    }

    /** Active accounts that hold the permission (at `$scope` or wider). */
    public function usersWith(string $permission, string $scope = Scope::ALL): Collection
    {
        $roleIds = DB::table('usr_roles as r')
            ->leftJoin('usr_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.kind', 'admin')
            ->orWhere(fn ($q) => $q->where('r.kind', 'standard')->where('p.name', $permission)
                ->whereIn('rp.scope', array_slice(Scope::ORDER, Scope::rank($scope))))
            ->distinct()->pluck('r.id');
        $ids = DB::table('usr_user_roles')->whereIn('role_id', $roleIds)->where('model_type', (new User)->getMorphClass())->pluck('user_id')
            ->map(fn ($id) => (int) $id);
        if (Scope::rank($scope) <= Scope::rank(Scope::DEPARTMENT) && $this->departmentRoleGrants($permission) !== null) {
            $ids = $ids->merge($this->departmentManagers());
        }

        return User::query()->whereIn('id', $ids->unique()->all())->where('status', 'active')->orderBy('id')->get();
    }

    /** Forget what was worked out: after a role or its grants change, and between requests. */
    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->memo = [];
            $this->managers = null;
            $this->department = null;
        } else {
            unset($this->memo[$userId]);
        }
    }

    /** @return array<string, string> */
    private function resolve(User $user): array
    {
        $roles = $this->roleRows($user);
        if ($roles->contains(fn ($role) => $role->kind === 'admin')) {
            return collect(PermissionCatalog::names())->mapWithKeys(fn ($name) => [$name => PermissionCatalog::widest($name)])->all();
        }

        $grants = [];
        $rows = DB::table('usr_role_permissions as rp')
            ->join('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roles->where('kind', 'standard')->pluck('id'))
            ->get(['p.name', 'rp.scope']);
        foreach ($rows as $row) {
            $this->add($grants, $row->name, $row->scope);
        }

        if (in_array($user->id, $this->departmentManagers(), true)) {
            foreach ($this->departmentRole() as $name => $scope) {
                $this->add($grants, $name, Scope::narrower($scope, Scope::DEPARTMENT));
            }
        }

        return $grants;
    }

    /** @param array<string, string> $grants */
    private function add(array &$grants, string $name, string $scope): void
    {
        if (! PermissionCatalog::has($name) || ! in_array($scope, PermissionCatalog::scopes($name), true)) {
            return; // stale rows never grant anything
        }
        $grants[$name] = Scope::wider($grants[$name] ?? null, $scope);
    }

    private function roleRows(User $user): Collection
    {
        return DB::table('usr_user_roles as ur')
            ->join('usr_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->where('ur.model_type', $user->getMorphClass())
            ->get(['r.id', 'r.kind']);
    }

    /** @return list<int> */
    private function departmentManagers(): array
    {
        return $this->managers ??= $this->directory->departmentManagerUserIds();
    }

    /** @return array<string, string> */
    private function departmentRole(): array
    {
        return $this->department ??= DB::table('usr_roles as r')
            ->join('usr_role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.kind', 'department')
            ->pluck('rp.scope', 'p.name')->all();
    }

    private function departmentRoleGrants(string $permission): ?string
    {
        return $this->departmentRole()[$permission] ?? null;
    }
}
