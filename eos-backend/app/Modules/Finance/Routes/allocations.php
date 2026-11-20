<?php

use App\Modules\Finance\Http\Controllers\AllocationController;
use Illuminate\Support\Facades\Route;

// Allocation of payroll and overhead to projects (phases 7–8, D13, §6.3). Reversal goes through POST /vouchers/{id}/reverse.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum', 'perm:finance.allocations'])->controller(AllocationController::class)->group(function () {
    Route::get('allocations/time-shares', 'timeShares');
    Route::put('allocations/time-shares', 'setTimeShares');
    Route::post('allocations/time-shares/copy-previous', 'copyTimeShares');
    Route::get('allocations', 'index');
    Route::post('allocations/preview', 'preview');
    Route::post('allocations', 'store');
    Route::get('allocations/{allocation}', 'show')->whereNumber('allocation');
});
