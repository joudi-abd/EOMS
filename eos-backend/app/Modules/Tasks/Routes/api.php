<?php

use App\Modules\Tasks\Http\Controllers\BoardController;
use App\Modules\Tasks\Http\Controllers\CardController;
use Illuminate\Support\Facades\Route;

// Every account with tasks.view reaches the module; what it may do on a board follows its board role (BoardAccess).
Route::prefix('api/v1/tasks')->middleware(['api', 'auth:sanctum', 'perm:tasks.view'])->group(function () {
    Route::controller(BoardController::class)->group(function () {
        Route::get('boards', 'index');
        Route::get('boards/options', 'options');
        Route::get('people', 'people');
        Route::post('boards', 'store');
        Route::get('boards/{board}', 'show')->whereNumber('board');
        Route::get('boards/{board}/changes', 'changes')->whereNumber('board');
        Route::patch('boards/{board}', 'update')->whereNumber('board');
        Route::post('boards/{board}/archive', 'archive')->whereNumber('board');
        Route::get('boards/{board}/archived', 'archived')->whereNumber('board');

        Route::post('boards/{board}/members', 'addMember')->whereNumber('board');
        Route::patch('boards/{board}/members/{user}', 'changeRole')->whereNumber(['board', 'user']);
        Route::delete('boards/{board}/members/{user}', 'removeMember')->whereNumber(['board', 'user']);

        Route::post('boards/{board}/lists', 'storeList')->whereNumber('board');
        Route::patch('lists/{list}', 'updateList')->whereNumber('list');
        Route::post('lists/{list}/archive', 'archiveList')->whereNumber('list');

        Route::post('boards/{board}/labels', 'storeLabel')->whereNumber('board');
        Route::patch('labels/{label}', 'updateLabel')->whereNumber('label');
        Route::delete('labels/{label}', 'destroyLabel')->whereNumber('label');

        Route::get('boards/{board}/recurrences', 'recurrences')->whereNumber('board');
        Route::post('boards/{board}/recurrences', 'storeRecurrence')->whereNumber('board');
        Route::put('recurrences/{recurrence}', 'updateRecurrence')->whereNumber('recurrence');
        Route::post('recurrences/{recurrence}/stop', 'stopRecurrence')->whereNumber('recurrence');
    });

    Route::controller(CardController::class)->group(function () {
        Route::get('my-cards', 'mine');
        Route::get('reports', 'report');

        Route::post('boards/{board}/cards', 'store')->whereNumber('board');
        Route::get('cards/{card}', 'show')->whereNumber('card');
        Route::patch('cards/{card}', 'update')->whereNumber('card');
        Route::put('cards/{card}/assignees', 'assignees')->whereNumber('card');
        Route::put('cards/{card}/labels', 'labels')->whereNumber('card');
        Route::post('cards/{card}/move', 'move')->whereNumber('card');
        Route::post('cards/{card}/approve', 'approve')->whereNumber('card');
        Route::post('cards/{card}/reject', 'reject')->whereNumber('card');
        Route::post('cards/{card}/reopen', 'reopen')->whereNumber('card');
        Route::post('cards/{card}/archive', 'archive')->whereNumber('card');
        Route::post('cards/{card}/watch', 'watch')->whereNumber('card');
        Route::post('cards/{card}/transfer', 'transfer')->whereNumber('card');
        Route::post('cards/{card}/copy', 'copy')->whereNumber('card');

        Route::post('cards/{card}/checklists', 'storeChecklist')->whereNumber('card');
        Route::patch('checklists/{checklist}', 'updateChecklist')->whereNumber('checklist');
        Route::delete('checklists/{checklist}', 'destroyChecklist')->whereNumber('checklist');
        Route::post('checklists/{checklist}/items', 'storeItem')->whereNumber('checklist');
        Route::patch('checklist-items/{item}', 'updateItem')->whereNumber('item');
        Route::delete('checklist-items/{item}', 'destroyItem')->whereNumber('item');

        Route::post('cards/{card}/comments', 'storeComment')->whereNumber('card');
        Route::patch('comments/{comment}', 'updateComment')->whereNumber('comment');
        Route::delete('comments/{comment}', 'destroyComment')->whereNumber('comment');

        Route::post('cards/{card}/attachments', 'storeAttachment')->whereNumber('card');
        Route::get('attachments/{attachment}', 'downloadAttachment')->whereNumber('attachment');
        Route::delete('attachments/{attachment}', 'destroyAttachment')->whereNumber('attachment');
    });
});
