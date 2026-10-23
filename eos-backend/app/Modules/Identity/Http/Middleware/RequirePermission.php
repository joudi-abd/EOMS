<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        abort_unless($request->user()?->can($permission), 403, 'ليست لديك صلاحية لهذا الإجراء.');

        return $next($request);
    }
}