<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        Gate::policy(User::class, UserPolicy::class);

        $this->app['router']->aliasMiddleware('perm', RequirePermission::class);
    }
}