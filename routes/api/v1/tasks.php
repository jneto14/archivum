<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

/*
| Starting work answers 202 and hands back the task. That is the whole contract
| for anything slow here — an export, a sweep of the archive, a node's
| documents being moved — and reading the task back is how a client waits.
|
| There is no signed download twin of the web route. That link exists so an
| emailed export can be fetched from a browser; a client already holds a token.
*/
Route::get('workspaces/{workspace}/tasks', [TaskController::class, 'index'])->name('workspaces.tasks.index');
Route::post('workspaces/{workspace}/tasks', [TaskController::class, 'store'])->name('workspaces.tasks.store');
Route::post('workspaces/{workspace}/tasks/reextract', [TaskController::class, 'reextract'])->name('workspaces.tasks.reextract');
Route::get('workspaces/{workspace}/tasks/{task}', [TaskController::class, 'show'])->name('workspaces.tasks.show');
Route::post('workspaces/{workspace}/tasks/{task}/retry', [TaskController::class, 'retry'])->name('workspaces.tasks.retry');
Route::get('workspaces/{workspace}/tasks/{task}/download', [TaskController::class, 'download'])->name('workspaces.tasks.download');

Route::get('workspaces/{workspace}/activity', [ActivityController::class, 'index'])->name('workspaces.activity.index');
