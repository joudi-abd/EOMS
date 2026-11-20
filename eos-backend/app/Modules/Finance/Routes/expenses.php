<?php

use App\Modules\Finance\Http\Controllers\ExpenseCategoryController;
use App\Modules\Finance\Http\Controllers\SiteRequestController;
use Illuminate\Support\Facades\Route;

// Expenses (phase 4): the expense category tree and supervisors' site requests (R24/R25). Expense vouchers
// (`type: expense`, `payment` with purpose `internal_expense`) go through /vouchers.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('perm:finance.view');
    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store']);
        Route::patch('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->whereNumber('category');
        Route::post('expense-categories/{category}/deactivate', [ExpenseCategoryController::class, 'deactivate'])->whereNumber('category');
    });

    // Supervisor side (mobile): submit with the invoice image, follow "my requests".
    Route::middleware('perm:finance.site_requests')->group(function () {
        Route::get('site-requests/mine', [SiteRequestController::class, 'mine']);
        Route::post('site-requests', [SiteRequestController::class, 'store']);
    });
    // The requester or finance (checked in the service).
    Route::get('site-requests/{siteRequest}', [SiteRequestController::class, 'show'])->whereNumber('siteRequest');
    Route::get('site-requests/{siteRequest}/attachments/{attachment}', [SiteRequestController::class, 'downloadAttachment'])
        ->whereNumber(['siteRequest', 'attachment']);

    // Finance inbox and decisions; supervisors never approve.
    Route::middleware('perm:finance.vouchers')->group(function () {
        Route::get('site-requests', [SiteRequestController::class, 'index']);
        Route::post('site-requests/{siteRequest}/approve', [SiteRequestController::class, 'approve'])->whereNumber('siteRequest');
        Route::post('site-requests/{siteRequest}/reject', [SiteRequestController::class, 'reject'])->whereNumber('siteRequest');
    });
});
