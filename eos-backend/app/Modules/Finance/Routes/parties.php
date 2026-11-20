<?php

use App\Modules\Finance\Http\Controllers\PartnerController;
use App\Modules\Finance\Http\Controllers\PartyController;
use Illuminate\Support\Facades\Route;

// Parties (phase 3): parties and their roles, statements (T12), partners and shares.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.view')->group(function () {
        Route::get('parties', [PartyController::class, 'index']);
        Route::get('parties/{party}', [PartyController::class, 'show'])->whereNumber('party');
        Route::get('parties/{party}/statement', [PartyController::class, 'statement'])->whereNumber('party');
        Route::get('partners', [PartnerController::class, 'index']);
    });

    Route::middleware('perm:finance.vouchers')->group(function () {
        Route::post('parties', [PartyController::class, 'store']);
        Route::post('parties/import', [PartyController::class, 'import']);
        Route::patch('parties/{party}', [PartyController::class, 'update'])->whereNumber('party');
        Route::post('parties/{party}/deactivate', [PartyController::class, 'deactivate'])->whereNumber('party');
        Route::post('partners', [PartnerController::class, 'store']);
        Route::patch('partners/{partner}', [PartnerController::class, 'update'])->whereNumber('partner');
    });
});
