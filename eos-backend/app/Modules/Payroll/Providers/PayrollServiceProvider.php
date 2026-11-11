<?php

namespace App\Modules\Payroll\Providers;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\Payroll\Listeners\BlockTerminationWhenOpenShamCashExport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        Event::listen(EmployeeTerminating::class, BlockTerminationWhenOpenShamCashExport::class);
    }
}
