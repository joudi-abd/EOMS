<?php

namespace App\Modules\Projects\Providers;

use App\Modules\Projects\Contracts\NullProjectBranchScope;
use App\Modules\Projects\Contracts\ProjectBranchScope;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ProjectBranchScope::class, NullProjectBranchScope::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
