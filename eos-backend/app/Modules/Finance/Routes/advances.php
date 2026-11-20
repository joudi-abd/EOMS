<?php

use App\Modules\Finance\Http\Controllers\CustodyController;
use App\Modules\Finance\Http\Controllers\EmployeeAdvanceController;
use Illuminate\Support\Facades\Route;

// Advances and custody (phase 6). Issuing, settling and returning go through POST /vouchers.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('employee-advances', [EmployeeAdvanceController::class, 'index']);
        Route::get('employee-advances/report', [EmployeeAdvanceController::class, 'report']);
        Route::get('employee-advances/{advance}', [EmployeeAdvanceController::class, 'show'])->whereNumber('advance');
        Route::get('custodies', [CustodyController::class, 'index']);
        Route::get('custodies/report', [CustodyController::class, 'report']);
        Route::get('custodies/{custody}', [CustodyController::class, 'show'])->whereNumber('custody');
    });

    Route::post('employee-advance-installments/{installment}/postpone', [EmployeeAdvanceController::class, 'postpone'])
        ->whereNumber('installment')->middleware('perm:finance.vouchers');

    Route::get('my-custody', [CustodyController::class, 'mine'])->middleware('perm:finance.site_requests');
});
