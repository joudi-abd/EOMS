<?php

use App\Modules\Finance\Http\Controllers\PeriodController;
use App\Modules\Finance\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// Setup wizard (spec §12) and the closing screen's month list (§9.8) — phase 11.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('setup/status', [SetupController::class, 'status']);
        Route::get('setup/opening-check', [SetupController::class, 'openingCheck']);
    });

    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('setup/starter-chart', [SetupController::class, 'starterChart']);
        Route::post('setup/opening-balances', [SetupController::class, 'openingBalances']);
    });

    Route::get('periods/closing', [PeriodController::class, 'overview'])->middleware('perm:finance.periods');
});
