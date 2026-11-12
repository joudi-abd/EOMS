<?php

use App\Modules\Identity\Http\Controllers\AccessController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\NotificationController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/auth')->middleware('api')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::put('users/{user}/roles', [UserController::class, 'assignRoles']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');

    // The catalogue and role labels are readable by every account (they hold no personal data);
    // grants are listed for account and role editors only (see AccessController::index).
    Route::get('access/catalog', [AccessController::class, 'catalog']);
    Route::get('access/roles', [AccessController::class, 'index']);
    Route::middleware('perm:roles.manage')->group(function () {
        Route::post('access/roles', [AccessController::class, 'store']);
        Route::patch('access/roles/{role}', [AccessController::class, 'update']);
        Route::delete('access/roles/{role}', [AccessController::class, 'destroy']);
        Route::post('access/roles/{role}/restore', [AccessController::class, 'restore']);
    });
});
