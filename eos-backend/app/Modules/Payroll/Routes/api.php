<?php

use App\Modules\Payroll\Http\Controllers\PayRunController;
use App\Modules\Payroll\Http\Controllers\SalaryComponentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/payroll')->middleware(['api', 'auth:sanctum'])->controller(PayRunController::class)->group(function () {
    // Read: HR, finance and the company manager (payroll.view). Payslips stay out of employee self-service.
    Route::middleware('perm:payroll.view')->group(function () {
        Route::get('runs', 'index');
        Route::get('runs/{run}', 'show')->whereNumber('run');
        Route::get('runs/{run}/lines/{line}', 'line')->whereNumber(['run', 'line']);
        Route::get('runs/{run}/export', 'export')->whereNumber('run');
    });
    // Prepare: HR (payroll.manage) sets the salaries, bonuses and deductions and sends the run to finance.
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('runs', 'store');
        Route::post('runs/{run}/recalculate', 'recalculate')->whereNumber('run');
        Route::post('runs/{run}/items', 'addItem')->whereNumber('run');
        Route::delete('items/{item}', 'removeItem')->whereNumber('item');
        Route::post('runs/{run}/submit', 'submit')->whereNumber('run');
    });
    // Review and approve: finance only (payroll.approve; OP-1 — the company manager reads payroll but does not approve it).
    Route::middleware('perm:payroll.approve')->group(function () {
        Route::post('items/{item}/review', 'reviewDeduction')->whereNumber('item');
        Route::post('runs/{run}/review-remaining', 'reviewRemaining')->whereNumber('run');
        Route::post('items/{item}/adjustment-decision', 'decideAdjustment')->whereNumber('item');
        Route::post('runs/{run}/return', 'returnToDraft')->whereNumber('run');
        Route::post('runs/{run}/approve', 'approve')->whereNumber('run');
    });
    // Pay: finance (payroll.pay) — one by one, or a Sham Cash bulk file confirmed once it is sent.
    Route::middleware('perm:payroll.pay')->group(function () {
        Route::post('lines/{line}/payment', 'pay')->whereNumber('line');
        Route::post('payments/{payment}/void', 'voidPayment')->whereNumber('payment');
        Route::post('runs/{run}/shamcash', 'shamCash')->whereNumber('run');
        Route::get('runs/{run}/shamcash-exports', 'shamCashExports')->whereNumber('run');
        Route::get('shamcash-exports/{export}/file', 'shamCashFile')->whereNumber('export');
        Route::post('shamcash-exports/{export}/confirm', 'confirmShamCash')->whereNumber('export');
        Route::post('shamcash-exports/{export}/cancel', 'cancelShamCash')->whereNumber('export');
    });
});

// Salary components (D19): finance defines them under its settings; HR gives recurring ones to employees.
Route::prefix('api/v1/payroll')->middleware(['api', 'auth:sanctum'])->controller(SalaryComponentController::class)->group(function () {
    Route::middleware('perm:payroll.view')->group(function () {
        Route::get('components', 'index');
        Route::get('employee-components', 'employeeComponents');
    });
    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('components', 'store');
        Route::patch('components/{component}', 'update')->whereNumber('component');
    });
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('employee-components', 'assign');
        Route::patch('employee-components/{assignment}', 'updateAssignment')->whereNumber('assignment');
        Route::delete('employee-components/{assignment}', 'removeAssignment')->whereNumber('assignment');
    });
});
