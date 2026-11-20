<?php

use App\Modules\Finance\Http\Controllers\DashboardController;
use App\Modules\Finance\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Reports T1–T23 and the dashboard (phase 10). Every list report also answers ?format=csv.
// T20 (asset register) lives in Routes/assets.php: GET reports/asset-register.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('perm:finance.reports');

    Route::prefix('reports')->group(function () {
        Route::middleware('perm:finance.reports')->group(function () {
            Route::get('box-balances', [ReportController::class, 'boxBalances']);             // T1
            Route::get('box-statement', [ReportController::class, 'boxStatement']);           // T2
            Route::get('journal', [ReportController::class, 'journal']);                      // T3
            Route::get('general-ledger', [ReportController::class, 'generalLedger']);         // T4
            Route::get('trial-balance', [ReportController::class, 'trialBalance']);           // T5
            Route::get('income-statement', [ReportController::class, 'incomeStatement']);     // T6
            Route::get('balance-sheet', [ReportController::class, 'balanceSheet']);           // T7
            Route::get('party-statement', [ReportController::class, 'partyStatement']);       // T12
            Route::get('contractor-balances', [ReportController::class, 'contractorBalances']); // T13
            Route::get('aging', [ReportController::class, 'aging']);                          // T14
            Route::get('employee-advances', [ReportController::class, 'employeeAdvances']);   // T15
            Route::get('open-custodies', [ReportController::class, 'openCustodies']);         // T16
            Route::get('partners', [ReportController::class, 'partners']);                    // T17
            Route::get('expenses-by-category', [ReportController::class, 'expensesByCategory']); // T18
            Route::get('payroll-cost', [ReportController::class, 'payrollCost']);             // T19
            Route::get('exchanges', [ReportController::class, 'exchanges']);                  // T21
            Route::get('cash-counts', [ReportController::class, 'cashCounts']);               // T22
        });

        // Project reports keep the project-finance reading permission (a project manager sees their own projects).
        Route::middleware('perm:finance.projects.view')->group(function () {
            Route::get('projects-summary', [ReportController::class, 'projectsSummary']);     // T8
            Route::get('project-statement', [ReportController::class, 'projectStatement']);   // T9
            Route::get('budget-vs-actual', [ReportController::class, 'budgetVsActual']);      // T10
            Route::get('project-profitability', [ReportController::class, 'profitability']);  // T11
        });

        Route::get('audit-log', [ReportController::class, 'audit'])->middleware('perm:finance.audit'); // T23
    });
});
