<?php

use App\Modules\Settings\Http\Controllers\BranchController;
use App\Modules\Settings\Http\Controllers\CurrencyController;
use App\Modules\Settings\Http\Controllers\ExchangeRateController;
use App\Modules\Settings\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// Public, read-only branding (company name) the login screen shows before anyone signs in.
Route::prefix('api/v1/settings')->middleware(['api', 'throttle:60,1'])
    ->get('config/public', [SettingController::class, 'publicSettings']);

Route::prefix('api/v1/settings')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        // Currencies and branches: everyone signed in reads them; finance settings change them.
        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::post('currencies', [CurrencyController::class, 'store'])->middleware('perm:finance.settings');
        Route::patch('currencies/{currency}', [CurrencyController::class, 'update'])->whereNumber('currency')->middleware('perm:finance.settings');

        Route::get('branches', [BranchController::class, 'index']);
        Route::post('branches', [BranchController::class, 'store'])->middleware('perm:finance.settings');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->middleware('perm:finance.settings');

        // Daily rate bulletin against the base currency.
        Route::get('exchange-rates', [ExchangeRateController::class, 'bulletin']);
        Route::get('exchange-rates/history', [ExchangeRateController::class, 'history']);
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->middleware('perm:finance.rates');
        Route::post('exchange-rates/copy-previous', [ExchangeRateController::class, 'copyPrevious'])->middleware('perm:finance.rates');

        Route::get('config/{group}', [
            SettingController::class, 'group',
        ])->middleware('perm:settings.view');

        Route::put('config/{key}', [
            SettingController::class, 'update',
        ])->middleware('perm:settings.manage');
    });
