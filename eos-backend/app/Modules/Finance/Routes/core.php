<?php

use App\Modules\Finance\Http\Controllers\AccountController;
use App\Modules\Finance\Http\Controllers\AuditLogController;
use App\Modules\Finance\Http\Controllers\JournalEntryController;
use App\Modules\Finance\Http\Controllers\PeriodController;
use App\Modules\Finance\Http\Controllers\SystemAccountController;
use App\Modules\Finance\Http\Controllers\UserBranchController;
use Illuminate\Support\Facades\Route;

// Core: chart of accounts, system-account roles, periods, journal, user↔branch reach, audit trail.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('system-accounts', [SystemAccountController::class, 'index']);
        Route::get('periods', [PeriodController::class, 'index']);
        Route::get('entries', [JournalEntryController::class, 'index']);
        Route::get('entries/{entry}', [JournalEntryController::class, 'show'])->whereNumber('entry');
        Route::get('my-branches', [UserBranchController::class, 'mine']);
    });

    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('accounts', [AccountController::class, 'store']);
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account');
        Route::post('accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->whereNumber('account');
        Route::put('system-accounts', [SystemAccountController::class, 'update']);
        Route::get('users/{user}/branches', [UserBranchController::class, 'show'])->whereNumber('user');
        Route::put('users/{user}/branches', [UserBranchController::class, 'update'])->whereNumber('user');
    });

    Route::middleware('perm:finance.periods')->group(function () {
        Route::get('periods/{month}/checks', [PeriodController::class, 'checks'])->where('month', '\d{4}-(0[1-9]|1[0-2])');
        Route::post('periods/{month}/close', [PeriodController::class, 'close'])->where('month', '\d{4}-(0[1-9]|1[0-2])');
        Route::post('periods/{month}/reopen', [PeriodController::class, 'reopen'])->where('month', '\d{4}-(0[1-9]|1[0-2])');
    });

    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('perm:finance.audit');
});
