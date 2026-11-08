<?php

namespace App\Modules\Payroll\Providers;

use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        Route::model('run', PayRun::class);
        Route::model('line', PayRunLine::class);
        Route::model('item', PayRunItem::class);
    }
}
