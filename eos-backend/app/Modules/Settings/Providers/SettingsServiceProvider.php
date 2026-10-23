<?php

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Console\CreateApiClientCommand;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateApiClientCommand::class]);
        }
    }
}