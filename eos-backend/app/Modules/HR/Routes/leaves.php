<?php

use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/leave-settings')
    ->middleware(['api', 'auth:sanctum', 'perm:leaves.manage'])
    ->controller(LeaveSettingsController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('types', 'storeType');
        Route::post('types/{leaveType}/policies', 'publishPolicy');
        Route::patch('types/{leaveType}/activation', 'updateActivation');
        Route::post('holidays', 'storeHoliday');
        Route::delete('holidays/{holiday}', 'destroyHoliday');
        Route::get('balance-entries', 'balanceEntries');
        Route::get('pending-openings', 'pendingOpenings');
        Route::post('balance-entries', 'storeBalanceEntry');
    });

Route::prefix('api/v1/leaves')->middleware(['api', 'auth:sanctum'])
    ->controller(LeaveRequestController::class)
    ->group(function () {
        Route::get('options', 'options');
        Route::put('half-days', 'halfDays')->middleware('perm:leaves.manage');
        Route::get('/', 'index');
        Route::get('calendar', 'calendar');
        Route::get('export', 'export');
        Route::post('preview', 'preview')->name('leaves.preview');
        Route::post('/', 'store');
        Route::get('{leaveRequest}', 'show')->whereNumber('leaveRequest');
        Route::post('{leaveRequest}/preview', 'preview')->name('leaves.preview-edit');
        Route::put('{leaveRequest}', 'store');
        Route::post('{leaveRequest}/{action}', 'action')
            ->whereIn('action', ['approve', 'reject', 'withdraw', 'cancel', 'shorten', 'approve-change', 'reject-change']);
    });
