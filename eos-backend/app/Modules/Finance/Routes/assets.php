<?php

use App\Modules\Finance\Http\Controllers\AssetCategoryController;
use App\Modules\Finance\Http\Controllers\AssetController;
use App\Modules\Finance\Http\Controllers\AssetOperationController;
use Illuminate\Support\Facades\Route;

// Assets (phase 9, D22): categories, register, purchase (#22), opening assets and CSV import (§12), custody transfer,
// maintenance (#23), disposal (#24, #25) and the T20 register report. No depreciation.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    // Reading the register is finance.view (the general manager views assets, spec §8); operating it is finance.assets.
    Route::get('asset-categories', [AssetCategoryController::class, 'index'])->middleware('perm:finance.view');
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('assets', [AssetController::class, 'index']);
        Route::get('assets/{asset}', [AssetController::class, 'show'])->whereNumber('asset');
    });
    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('asset-categories', [AssetCategoryController::class, 'store']);
        Route::patch('asset-categories/{category}', [AssetCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('asset-categories/{category}', [AssetCategoryController::class, 'destroy'])->whereNumber('category');
    });

    Route::middleware('perm:finance.assets')->group(function () {
        Route::patch('assets/{asset}', [AssetController::class, 'update'])->whereNumber('asset');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->whereNumber('asset');
        Route::post('assets/purchase', [AssetController::class, 'purchase']);
        Route::post('assets/opening', [AssetController::class, 'opening']);
        Route::post('assets/import', [AssetController::class, 'import']);
        Route::post('assets/{asset}/transfer', [AssetOperationController::class, 'transfer'])->whereNumber('asset');
        Route::post('assets/{asset}/status', [AssetOperationController::class, 'status'])->whereNumber('asset');
        Route::post('assets/{asset}/maintenance', [AssetOperationController::class, 'maintenance'])->whereNumber('asset');
        Route::post('assets/{asset}/dispose', [AssetOperationController::class, 'dispose'])->whereNumber('asset');
    });

    Route::get('reports/asset-register', [AssetController::class, 'report'])->middleware('perm:finance.reports');
});
