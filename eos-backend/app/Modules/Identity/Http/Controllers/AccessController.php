<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\PermissionCatalog;
use App\Modules\Identity\Access\RoleService;
use App\Modules\Identity\Access\Scope;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** The permission catalogue and the roles screen. */
#[Group('02 - Access', weight: 2)]
class AccessController extends Controller
{
    public function __construct(private RoleService $roles, private Access $access) {}

    /** Every permission grouped by module, with labels, hints and the scopes it may be granted with. */
    public function catalog()
    {
        $modules = [];
        foreach (PermissionCatalog::modules() as $key => $module) {
            $modules[] = [
                'key' => $key, 'label_ar' => $module['ar'], 'label_en' => $module['en'],
                'permissions' => collect($module['permissions'])->map(fn ($p, $name) => [
                    'name' => $name, 'label_ar' => $p['ar'], 'label_en' => $p['en'],
                    'hint_ar' => $p['hint_ar'], 'hint_en' => $p['hint_en'],
                    'scopes' => $p['scopes'], 'sensitive' => $p['sensitive'] ?? false,
                ])->values(),
            ];
        }

        return ApiResponse::ok(['scopes' => Scope::ORDER, 'modules' => $modules]);
    }

    /**
     * Roles with their labels. Their grants are included for whoever edits accounts or roles,
     * so the account form can show what a role gives.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $detailed = $this->access->allows($user, 'roles.manage') || $this->access->allows($user, 'users.manage');
        $rows = $this->roles->list()->map(fn (array $role) => $detailed ? $role : array_diff_key($role, ['grants' => 1, 'holders' => 1]));

        return ApiResponse::ok($rows->values());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('usr_roles', 'name')],
            ...$this->fieldRules(true),
        ]);

        return ApiResponse::created($this->roles->create($request->user(), $data));
    }

    public function update(Request $request, string $role)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], ...$this->fieldRules(false)]);

        return ApiResponse::ok($this->roles->update($request->user(), $role, $data));
    }

    public function destroy(Request $request, string $role)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->roles->delete($request->user(), $role, (int) $data['version']);

        return ApiResponse::message('حُذف الدور.');
    }

    /** Puts a shipped role back to its code definition. */
    public function restore(Request $request, string $role)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::ok($this->roles->restore($request->user(), $role, (int) $data['version']));
    }

    private function fieldRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'label_ar' => [$required, 'string', 'max:80'],
            'label_en' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hint_ar' => ['sometimes', 'nullable', 'string', 'max:300'],
            'hint_en' => ['sometimes', 'nullable', 'string', 'max:300'],
            // { "permission.name": "all" | "department" | "own" } — checked against the catalogue by RoleService
            // (keys hold dots, so the validator's wildcards cannot reach them).
            'grants' => [$creating ? 'present' : 'sometimes', 'array'],
        ];
    }
}
