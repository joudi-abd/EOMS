<?php

namespace App\Modules\Identity\Access;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles as records an administrator edits: labels, a description and scoped grants.
 *
 * Guards against escalation: nobody grants a permission (or a scope) wider than they hold themselves, nobody
 * edits the administrator role, and only an administrator hands out the administrator role.
 */
class RoleService
{
    public function __construct(private Access $access, private AuditLogger $audit) {}

    /** Every role with its grants and how many active accounts hold it. */
    public function list(): Collection
    {
        $grants = DB::table('usr_role_permissions as rp')->join('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->get(['rp.role_id', 'p.name', 'rp.scope'])->groupBy('role_id');
        $counts = DB::table('usr_user_roles as ur')->join('usr_users as u', 'u.id', '=', 'ur.user_id')
            ->where('ur.model_type', (new User)->getMorphClass())->where('u.status', 'active')
            ->groupBy('ur.role_id')->selectRaw('ur.role_id, count(*) as n')->pluck('n', 'role_id');
        $order = ['admin' => 0, 'standard' => 1, 'department' => 2];

        return DB::table('usr_roles')->where('guard_name', 'web')->get()
            ->sortBy(fn ($role) => [$order[$role->kind] ?? 9, $role->is_default ? 0 : 1, $role->id])->values()
            ->map(fn ($role) => $this->present($role, $grants->get($role->id, collect()), (int) ($counts[$role->id] ?? 0)));
    }

    public function find(string $name): ?object
    {
        return DB::table('usr_roles')->where('name', $name)->where('guard_name', 'web')->first();
    }

    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            $grants = $this->checkedGrants($actor, $data['grants'] ?? []);
            $id = DB::table('usr_roles')->insertGetId([
                'name' => $data['name'], 'guard_name' => 'web',
                'label_ar' => $data['label_ar'], 'label_en' => $data['label_en'] ?? $data['label_ar'],
                'hint_ar' => $data['hint_ar'] ?? null, 'hint_en' => $data['hint_en'] ?? null,
                'kind' => 'standard', 'is_default' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeGrants($id, $grants);
            $this->audit->log('role.created', null, null, ['role' => $data['name'], 'grants' => $grants], $actor->id);
            $this->changed();

            return $this->presentById($id);
        }, 3);
    }

    public function update(User $actor, string $name, array $data): array
    {
        return DB::transaction(function () use ($actor, $name, $data) {
            $role = $this->lockEditable($actor, $name, (int) $data['version']);
            $before = $this->grantsOf($role->id);
            $values = array_intersect_key($data, array_flip(['label_ar', 'label_en', 'hint_ar', 'hint_en']));
            if (array_key_exists('grants', $data)) {
                $grants = $this->checkedGrants($actor, $data['grants'], $before);
                if ($role->kind === 'department') {
                    $grants = array_map(fn ($scope) => Scope::narrower($scope, Scope::DEPARTMENT), $grants);
                }
                DB::table('usr_role_permissions')->where('role_id', $role->id)->delete();
                $this->writeGrants($role->id, $grants);
            }
            DB::table('usr_roles')->where('id', $role->id)->update($values + ['version' => $role->version + 1, 'updated_at' => now()]);
            $this->audit->log('role.updated', null,
                ['role' => $name, 'grants' => $before] + array_intersect_key((array) $role, $values),
                ['role' => $name, 'grants' => $this->grantsOf($role->id)] + $values, $actor->id);
            $this->changed();

            return $this->presentById($role->id);
        }, 3);
    }

    public function delete(User $actor, string $name, int $version): void
    {
        DB::transaction(function () use ($actor, $name, $version) {
            $role = $this->lockEditable($actor, $name, $version);
            if ($role->is_default) {
                throw ValidationException::withMessages(['role' => ['الأدوار الأساسية لا تُحذف؛ يمكن تعديلها أو إرجاعها للأصل.']]);
            }
            if (DB::table('usr_user_roles')->where('role_id', $role->id)->exists()) {
                throw ValidationException::withMessages(['role' => ['هذا الدور مسند لحسابات؛ انقلها إلى دور آخر قبل حذفه.']]);
            }
            $before = $this->grantsOf($role->id);
            DB::table('usr_roles')->where('id', $role->id)->delete();
            $this->audit->log('role.deleted', null, ['role' => $name, 'grants' => $before], null, $actor->id);
            $this->changed();
        }, 3);
    }

    /** Puts a shipped role back to its code definition. */
    public function restore(User $actor, string $name, int $version): array
    {
        $definition = DefaultRoles::all()[$name] ?? null;
        abort_if($definition === null, 422, 'هذا الدور ليس من الأدوار الأساسية.');

        return $this->update($actor, $name, [
            'version' => $version, 'label_ar' => $definition['ar'], 'label_en' => $definition['en'],
            'hint_ar' => $definition['hint_ar'], 'hint_en' => $definition['hint_en'], 'grants' => $definition['grants'],
        ]);
    }

    /**
     * Checks a set of role names an actor wants to give an account: they must exist and be assignable,
     * the administrator role needs an administrator, and no role may carry more than the actor holds.
     *
     * @param  list<string>  $names
     */
    public function ensureAssignable(User $actor, array $names, array $current = []): void
    {
        $roles = DB::table('usr_roles')->where('guard_name', 'web')->whereIn('name', $names)->get()->keyBy('name');
        foreach ($names as $name) {
            $role = $roles->get($name);
            if ($role === null || $role->kind === 'department') {
                throw ValidationException::withMessages(['roles' => ["الدور «{$name}» لا يُسند يدوياً."]]);
            }
            if (in_array($name, $current, true)) {
                continue; // keeping what the account already has is never an escalation
            }
            if ($role->kind === 'admin') {
                if (! $this->access->isAdmin($actor)) {
                    throw ValidationException::withMessages(['roles' => ['فقط مدير النظام يسند دور مدير النظام.']]);
                }

                continue;
            }
            $this->checkedGrants($actor, $this->grantsOf($role->id), [], 'roles');
        }
    }

    /** @return array<string, string> */
    public function grantsOf(int $roleId): array
    {
        return DB::table('usr_role_permissions as rp')->join('usr_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)->orderBy('p.name')->pluck('rp.scope', 'p.name')->all();
    }

    /**
     * Validates grants against the catalogue and the actor's own reach. A grant the role already had is kept
     * even when the actor lacks it, so a partial editor never strips what they could not have given.
     *
     * @param  array<string, string>  $grants
     * @param  array<string, string>  $before
     * @return array<string, string>
     */
    private function checkedGrants(User $actor, array $grants, array $before = [], string $field = 'grants'): array
    {
        $own = $this->access->grants($actor);
        $clean = [];
        foreach ($grants as $permission => $scope) {
            if (! PermissionCatalog::has($permission)) {
                throw ValidationException::withMessages([$field => ["صلاحية غير معروفة: {$permission}"]]);
            }
            if (! in_array($scope, PermissionCatalog::scopes($permission), true)) {
                throw ValidationException::withMessages([$field => ["النطاق «{$scope}» غير متاح للصلاحية {$permission}."]]);
            }
            $unchanged = ($before[$permission] ?? null) !== null && Scope::covers($before[$permission], $scope);
            if (! $unchanged && ! Scope::covers($own[$permission] ?? null, $scope)) {
                throw ValidationException::withMessages([$field => ["لا يمكنك منح صلاحية «{$permission}» بنطاق أوسع مما تملكه."]]);
            }
            $clean[$permission] = $scope;
        }
        // Grants the actor could not have given are not silently removed either.
        foreach ($before as $permission => $scope) {
            if (! isset($clean[$permission]) && ! Scope::covers($own[$permission] ?? null, $scope)) {
                throw ValidationException::withMessages([$field => ["لا يمكنك سحب صلاحية «{$permission}» لأنك لا تملكها."]]);
            }
        }
        ksort($clean);

        return $clean;
    }

    private function lockEditable(User $actor, string $name, int $version): object
    {
        $role = DB::table('usr_roles')->where('name', $name)->where('guard_name', 'web')->lockForUpdate()->first();
        abort_if($role === null, 404, 'الدور غير موجود.');
        abort_if($role->kind === 'admin', 422, 'دور مدير النظام يملك كل الصلاحيات دائماً ولا يُعدّل.');
        abort_unless((int) $role->version === $version, 409, 'عدّل شخص آخر هذا الدور؛ حدّث الصفحة.');

        return $role;
    }

    /** @param array<string, string> $grants */
    private function writeGrants(int $roleId, array $grants): void
    {
        $ids = DB::table('usr_permissions')->where('guard_name', 'web')->whereIn('name', array_keys($grants))->pluck('id', 'name');
        foreach ($grants as $permission => $scope) {
            DB::table('usr_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $ids[$permission], 'scope' => $scope]);
        }
    }

    private function changed(): void
    {
        DB::afterCommit(function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->access->flush();
        });
    }

    private function presentById(int $id): array
    {
        $role = DB::table('usr_roles')->find($id);
        $count = DB::table('usr_user_roles')->where('role_id', $id)->count();

        return $this->present($role, collect($this->grantsOf($id))->map(fn ($scope, $name) => (object) ['name' => $name, 'scope' => $scope])->values(), $count);
    }

    private function present(object $role, Collection $grants, int $holders): array
    {
        $map = $role->kind === 'admin'
            ? collect(PermissionCatalog::names())->mapWithKeys(fn ($name) => [$name => PermissionCatalog::widest($name)])->all()
            : $grants->filter(fn ($row) => PermissionCatalog::has($row->name))->pluck('scope', 'name')->sortKeys()->all();

        return [
            'name' => $role->name,
            'label_ar' => $role->label_ar ?? $role->name, 'label_en' => $role->label_en ?? $role->name,
            'hint_ar' => $role->hint_ar, 'hint_en' => $role->hint_en,
            'kind' => $role->kind, 'is_default' => (bool) $role->is_default, 'version' => (int) $role->version,
            'holders' => $holders, 'grants' => (object) $map,
        ];
    }
}
