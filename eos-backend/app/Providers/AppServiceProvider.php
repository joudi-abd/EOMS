<?php

namespace App\Providers;

use App\Support\ConcurrencyErrors;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Used by DB::transaction() to decide whether to retry the whole transaction.
        $this->app->singleton(ConcurrencyErrorDetector::class, ConcurrencyErrors::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Model::preventLazyLoading(! app()->isProduction());

    }
}
