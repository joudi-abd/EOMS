<?php

use App\Modules\Settings\Http\Controllers\ExchangeRateController;
use App\Modules\Settings\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/settings')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {

        Route::get('currencies', [
            ExchangeRateController::class, 'currencies',
        ]);

        Route::get('exchange-rates/latest', [
            ExchangeRateController::class, 'latest',
        ]);

        Route::post('exchange-rates', [
            ExchangeRateController::class, 'store',
        ])->middleware('perm:finance.manage');

        Route::get('config/public', [
            SettingController::class, 'publicSettings',
        ]);

        Route::get('config/{group}', [
            SettingController::class, 'group',
        ])->middleware('perm:settings.view');

        Route::put('config/{key}', [
            SettingController::class, 'update',
        ])->middleware('perm:settings.manage');
    });
