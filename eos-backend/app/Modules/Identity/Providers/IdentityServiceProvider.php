<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\PermissionCatalog;
use App\Modules\Identity\Console\SyncPermissions;
use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Contracts\NullEmployeeDirectory;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default only: the HR module binds the real directory regardless of provider order.
        $this->app->bindIf(EmployeeDirectory::class, NullEmployeeDirectory::class);
        $this->app->singleton(Access::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // Every catalogued ability is answered by Access (roles + department management, with scopes);
        // anything else falls through to policies. Spatie's own permission check is switched off in config.
        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && PermissionCatalog::has($ability)) {
                return $this->app->make(Access::class)->allows($user, $ability);
            }

            return null;
        });
        // Grants are worked out once per request.
        Event::listen(RequestHandled::class, fn () => $this->app->make(Access::class)->flush());

        $this->app['router']->aliasMiddleware('perm', RequirePermission::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissions::class]);
        }
    }
}
