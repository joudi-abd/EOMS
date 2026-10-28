<?php

namespace App\Modules\HR\Providers;

use App\Modules\HR\Console\Commands\ProcessExpiredContracts;
use App\Modules\HR\Console\Commands\ProcessScheduledContractTerminations;
use App\Modules\HR\Listeners\ProtectEmployeeAccountProfile;
use App\Modules\Identity\Events\UserProfileUpdating;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        Event::listen(
            UserProfileUpdating::class,
            ProtectEmployeeAccountProfile::class
        );

        $this->commands([
            ProcessScheduledContractTerminations::class,
        ]);

        $this->commands([
            ProcessScheduledContractTerminations::class,
            ProcessExpiredContracts::class,
        ]);
    }
}
