<?php

use App\Modules\Payroll\Http\Controllers\PayRunController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/payroll')->middleware(['api', 'auth:sanctum'])->controller(PayRunController::class)->group(function () {
    // Read: finance and the company manager (payroll.view). Payslips stay out of employee self-service.
    Route::middleware('perm:payroll.view')->group(function () {
        Route::get('runs', 'index');
        Route::get('runs/{run}', 'show')->whereNumber('run');
        Route::get('runs/{run}/lines/{line}', 'line')->whereNumber(['run', 'line']);
        Route::get('runs/{run}/export', 'export')->whereNumber('run');
    });
    // Prepare and pay: finance (payroll.manage).
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('runs', 'store');
        Route::post('runs/{run}/recalculate', 'recalculate')->whereNumber('run');
        Route::post('runs/{run}/items', 'addItem')->whereNumber('run');
        Route::delete('items/{item}', 'removeItem')->whereNumber('item');
        Route::post('items/{item}/adjustment-decision', 'decideAdjustment')->whereNumber('item');
        Route::post('runs/{run}/submit', 'submit')->whereNumber('run');
        Route::post('lines/{line}/payment', 'pay')->whereNumber('line');
        Route::post('payments/{payment}/void', 'voidPayment')->whereNumber('payment');
    });
    // Review and approve: company manager (payroll.approve).
    Route::middleware('perm:payroll.approve')->group(function () {
        Route::post('items/{item}/review', 'reviewDeduction')->whereNumber('item');
        Route::post('runs/{run}/return', 'returnToDraft')->whereNumber('run');
        Route::post('runs/{run}/approve', 'approve')->whereNumber('run');
    });
});
