<?php

namespace App\Modules\Identity\Access;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the code's catalogue and shipped roles to the database. Safe to run on every deploy:
 * - permissions missing from the catalogue are deleted (with their grants); new ones are created;
 * - a shipped role that does not exist yet is created with its default grants;
 * - a permission that appears for the first time is granted to the shipped roles whose defaults include it;
 * - everything else an administrator changed on the roles screen is kept.
 * `$reset` puts every shipped role back to its code definition.
 */
class AccessSync
{
    public function __construct(private Access $access) {}

    /** @return array{created: list<string>, removed: list<string>, roles: list<string>} */
    public function run(bool $reset = false): array
    {
        return DB::transaction(function () use ($reset) {
            $catalog = PermissionCatalog::names();
            $existing = DB::table('usr_permissions')->where('guard_name', 'web')->pluck('name')->all();
            $created = array_values(array_diff($catalog, $existing));
            $removed = array_values(array_diff($existing, $catalog));

            DB::table('usr_permissions')->where('guard_name', 'web')->whereIn('name', $removed)->delete();
            foreach ($created as $name) {
                DB::table('usr_permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
            $ids = DB::table('usr_permissions')->where('guard_name', 'web')->pluck('id', 'name');

            $roles = [];
            foreach (DefaultRoles::all() as $name => $definition) {
                $role = DB::table('usr_roles')->where('name', $name)->where('guard_name', 'web')->first();
                $fresh = $role === null;
                $values = [
                    'label_ar' => $definition['ar'], 'label_en' => $definition['en'],
                    'hint_ar' => $definition['hint_ar'], 'hint_en' => $definition['hint_en'],
                    'kind' => $definition['kind'], 'is_default' => true, 'updated_at' => now(),
                ];
                if ($fresh) {
                    $roleId = DB::table('usr_roles')->insertGetId($values + ['name' => $name, 'guard_name' => 'web', 'created_at' => now()]);
                } else {
                    $roleId = $role->id;
                    // Labels and grants stay as edited unless asked to reset; the kind always follows the code.
                    DB::table('usr_roles')->where('id', $roleId)->update($reset ? $values : ['kind' => $definition['kind'], 'is_default' => true]);
                }

                $grants = $definition['grants'];
                if (! $fresh && ! $reset) {
                    $grants = array_intersect_key($grants, array_flip($created));
                }
                if ($reset && ! $fresh) {
                    DB::table('usr_role_permissions')->where('role_id', $roleId)->delete();
                    DB::table('usr_roles')->where('id', $roleId)->increment('version');
                }
                foreach ($grants as $permission => $scope) {
                    DB::table('usr_role_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $ids[$permission]],
                        ['scope' => $scope]);
                }
                if ($fresh || $reset || $grants !== []) {
                    $roles[] = $name;
                }
            }

            DB::afterCommit(function () {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->access->flush();
            });

            return compact('created', 'removed', 'roles');
        });
    }
}
