<?php

namespace App\Modules\Payroll\Providers;

use App\Modules\Finance\Contracts\PeriodCloseCheck;
use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\Payroll\Listeners\BlockTerminationWhenOpenShamCashExport;
use App\Modules\Payroll\Services\PayrollPeriodCheck;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Finance asks this before closing a month, without depending on Payroll.
        $this->app->tag([PayrollPeriodCheck::class], PeriodCloseCheck::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        Event::listen(EmployeeTerminating::class, BlockTerminationWhenOpenShamCashExport::class);
    }
}
