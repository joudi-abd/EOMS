<?php

namespace App\Modules\Tasks\Providers;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\Tasks\Console\RunRecurringCards;
use App\Modules\Tasks\Console\SendDueReminders;
use App\Modules\Tasks\Listeners\ReleaseTasksOnTermination;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        Event::listen(EmployeeTerminating::class, ReleaseTasksOnTermination::class);
        if ($this->app->runningInConsole()) {
            $this->commands([RunRecurringCards::class, SendDueReminders::class]);
        }
    }
}
