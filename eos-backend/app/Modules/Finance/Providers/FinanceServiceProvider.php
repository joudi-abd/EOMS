<?php

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Console\NotifyOverdueCustodies;
use App\Modules\Finance\Console\RebuildBalances;
use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Models\SiteRequest;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Projects\Contracts\ProjectBranchScope;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Projects scopes "own" project lists by the branches finance assigns to each account.
        $this->app->bind(ProjectBranchScope::class, FinanceScope::class);
    }

    public function boot(): void
    {
        // Stable names for attachments (fin_attachments.attachable_type) and the audit log.
        Relation::morphMap(['fin_voucher' => Voucher::class, 'fin_cash_count' => CashCount::class, 'fin_site_request' => SiteRequest::class,
            'fin_asset' => Asset::class]);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        // One route file per area (core, treasury, parties, expenses, projects, advances, assets, allocations, reports).
        foreach (glob(__DIR__.'/../Routes/*.php') ?: [] as $routes) {
            $this->loadRoutesFrom($routes);
        }
        if ($this->app->runningInConsole()) {
            $this->commands([RebuildBalances::class, NotifyOverdueCustodies::class]);
        }
    }
}
