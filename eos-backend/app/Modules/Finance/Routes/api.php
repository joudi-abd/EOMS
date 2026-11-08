<?php

use App\Modules\Finance\Http\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->controller(FinanceController::class)->group(function () {
    // Read: finance and the company manager.
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('accounts', 'accounts');
        Route::get('entries', 'entries');
        Route::get('periods', 'periods');
        Route::get('approval-limits', 'limits');
        Route::get('boxes', 'boxes');
        Route::get('transactions', 'transactions');
        Route::get('parties', 'parties');
        Route::get('items', 'items');
        Route::get('items/{item}/moves', 'stockMoves')->whereNumber('item');
        Route::get('invoices', 'invoicesIndex');
        Route::get('invoices/{invoice}', 'showInvoice')->whereNumber('invoice');
        Route::get('reports/{report}', 'report')->whereIn('report', ['box', 'ledger', 'party', 'trial-balance', 'income-statement', 'balance-sheet']);
    });
    // Record: finance (finance.manage).
    Route::middleware('perm:finance.manage')->group(function () {
        Route::post('accounts', 'storeAccount');
        Route::patch('accounts/{account}', 'updateAccount')->whereNumber('account');
        Route::post('entries', 'storeEntry');
        Route::post('entries/{entry}/reverse', 'reverseEntry')->whereNumber('entry');
        Route::post('periods/close-request', 'requestClose');
        Route::post('boxes', 'storeBox');
        Route::patch('boxes/{box}', 'updateBox')->whereNumber('box');
        Route::post('transactions/{kind}', 'storeTransaction')->whereIn('kind', ['receipt', 'payment', 'transfer', 'exchange']);
        Route::post('transactions/{transaction}/reverse', 'reverseTransaction')->whereNumber('transaction');
        Route::post('parties', 'storeParty');
        Route::patch('parties/{party}', 'updateParty')->whereNumber('party');
        Route::post('items', 'storeItem');
        Route::patch('items/{item}', 'updateItem')->whereNumber('item');
        Route::post('items/{item}/adjust', 'adjustStock')->whereNumber('item');
        Route::post('invoices', 'saveInvoice');
        Route::put('invoices/{invoice}', 'saveInvoice')->whereNumber('invoice');
        Route::post('invoices/{invoice}/submit', 'submitInvoice')->whereNumber('invoice');
        Route::post('invoices/{invoice}/cancel', 'cancelInvoice')->whereNumber('invoice');
    });
    // Approve: company manager (finance.approve).
    Route::middleware('perm:finance.approve')->group(function () {
        Route::put('approval-limits', 'setLimit');
        Route::post('transactions/{transaction}/decision', 'decideTransaction')->whereNumber('transaction');
        Route::post('invoices/{invoice}/decision', 'decideInvoice')->whereNumber('invoice');
        Route::post('periods/{month}/decision', 'decideClose')->where('month', '\d{4}-\d{2}');
        Route::post('periods/{month}/reopen', 'reopen')->where('month', '\d{4}-\d{2}');
    });
});
