<?php

use App\Modules\Finance\Http\Controllers\CashBoxController;
use App\Modules\Finance\Http\Controllers\CashCountController;
use App\Modules\Finance\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

// Treasury: cash boxes, vouchers (every type), cash counts.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('cash-boxes', [CashBoxController::class, 'index']);
        Route::get('cash-boxes/{box}', [CashBoxController::class, 'show'])->whereNumber('box');
        Route::get('vouchers', [VoucherController::class, 'index']);
        Route::get('vouchers/suggest-rate', [VoucherController::class, 'suggestRate']);
        Route::get('vouchers/{voucher}', [VoucherController::class, 'show'])->whereNumber('voucher');
        Route::get('vouchers/{voucher}/attachments/{attachment}', [VoucherController::class, 'downloadAttachment'])->whereNumber(['voucher', 'attachment']);
        Route::get('cash-counts', [CashCountController::class, 'index']);
        Route::get('cash-counts/{count}', [CashCountController::class, 'show'])->whereNumber('count');
        Route::get('cash-counts/{count}/attachments/{attachment}', [CashCountController::class, 'downloadAttachment'])->whereNumber(['count', 'attachment']);
    });

    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('cash-boxes', [CashBoxController::class, 'store']);
        Route::patch('cash-boxes/{box}', [CashBoxController::class, 'update'])->whereNumber('box');
        Route::post('cash-boxes/{box}/deactivate', [CashBoxController::class, 'deactivate'])->whereNumber('box');
    });

    Route::middleware('perm:finance.vouchers')->group(function () {
        Route::post('vouchers', [VoucherController::class, 'store']);
        Route::put('vouchers/{voucher}', [VoucherController::class, 'update'])->whereNumber('voucher');
        Route::delete('vouchers/{voucher}', [VoucherController::class, 'destroy'])->whereNumber('voucher');
        Route::post('vouchers/{voucher}/submit', [VoucherController::class, 'submit'])->whereNumber('voucher');
        Route::post('vouchers/{voucher}/approve', [VoucherController::class, 'approve'])->whereNumber('voucher');
        Route::post('vouchers/{voucher}/reject', [VoucherController::class, 'reject'])->whereNumber('voucher');
        Route::post('vouchers/{voucher}/attachments', [VoucherController::class, 'storeAttachment'])->whereNumber('voucher');
        Route::delete('vouchers/{voucher}/attachments/{attachment}', [VoucherController::class, 'destroyAttachment'])->whereNumber(['voucher', 'attachment']);
    });
    Route::post('vouchers/{voucher}/reverse', [VoucherController::class, 'reverse'])->whereNumber('voucher')->middleware('perm:finance.reverse');

    Route::middleware('perm:finance.counts')->group(function () {
        Route::post('cash-counts', [CashCountController::class, 'store']);
        Route::put('cash-counts/{count}', [CashCountController::class, 'update'])->whereNumber('count');
        Route::delete('cash-counts/{count}', [CashCountController::class, 'destroy'])->whereNumber('count');
        Route::post('cash-counts/{count}/submit', [CashCountController::class, 'submit'])->whereNumber('count');
        Route::post('cash-counts/{count}/resnapshot', [CashCountController::class, 'resnapshot'])->whereNumber('count');
        Route::post('cash-counts/{count}/attachments', [CashCountController::class, 'storeAttachment'])->whereNumber('count');
    });
    Route::middleware('perm:finance.counts.approve')->group(function () {
        Route::post('cash-counts/{count}/approve', [CashCountController::class, 'approve'])->whereNumber('count');
        Route::post('cash-counts/{count}/reject', [CashCountController::class, 'reject'])->whereNumber('count');
    });
});
