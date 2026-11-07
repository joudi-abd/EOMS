<?php

use App\Modules\HR\Http\Controllers\LeaveSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/leave-settings')
    ->middleware(['api', 'auth:sanctum', 'perm:employees.manage'])
    ->controller(LeaveSettingsController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('types', 'storeType');
        Route::post('types/{leaveType}/policies', 'publishPolicy');
        Route::patch('types/{leaveType}/activation', 'updateActivation');
        Route::post('holidays', 'storeHoliday');
        Route::delete('holidays/{holiday}', 'destroyHoliday');
        Route::get('balance-entries', 'balanceEntries');
        Route::post('balance-entries', 'storeBalanceEntry');
    });
