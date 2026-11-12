<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use Closure;
use Illuminate\Http\Request;

/**
 * `perm:x` — the account holds permission x at any scope; `perm:x,all` — at company-wide scope
 * (or whichever scope is named). Unknown permissions are refused, so a typo never opens a route.
 */
class RequirePermission
{
    public function __construct(private Access $access) {}

    public function handle(Request $request, Closure $next, string $permission, string $scope = Scope::OWN): mixed
    {
        $user = $request->user();
        abort_unless($user !== null && $this->access->covers($user, $permission, $scope), 403, 'ليست لديك صلاحية لهذا الإجراء.');

        return $next($request);
    }
}
