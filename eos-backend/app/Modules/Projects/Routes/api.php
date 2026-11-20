<?php

use App\Modules\Projects\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/projects')->middleware(['api', 'auth:sanctum'])->controller(ProjectController::class)->group(function () {
    // Read: whoever views project finance (own = the projects they manage, or their branches' projects).
    Route::middleware('perm:finance.projects.view')->group(function () {
        Route::get('/', 'index');
        Route::get('{project}', 'show')->whereNumber('project');
    });
    // Create and edit: finance (finance.projects; own = the branches assigned to the account).
    Route::middleware('perm:finance.projects')->group(function () {
        Route::post('/', 'store');
        Route::patch('{project}', 'update')->whereNumber('project');
        Route::post('{project}/status', 'status')->whereNumber('project');
        Route::put('{project}/members', 'members')->whereNumber('project');
    });
});
