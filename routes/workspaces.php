<?php

declare(strict_types=1);

use App\Http\Controllers\Workspaces\TaskController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use App\Http\Controllers\Workspaces\WorkspaceLimitController;
use App\Http\Controllers\Workspaces\WorkspaceSettingsController;
use App\Http\Controllers\Workspaces\WorkspaceSwitchController;
use App\Http\Controllers\Workspaces\WorkspaceUsageController;
use App\Http\Controllers\Workspaces\WorkspaceUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/switch', [WorkspaceSwitchController::class, 'store'])->name('workspaces.switch');
    Route::get('workspaces/{workspace}/usage', [WorkspaceUsageController::class, 'show'])->name('workspaces.usage');
    Route::patch('workspaces/{workspace}/limits', [WorkspaceLimitController::class, 'update'])->name('workspaces.limits.update');
    Route::get('workspaces/{workspace}/settings', [WorkspaceSettingsController::class, 'show'])->name('workspaces.settings.show');

    Route::get('workspaces/{workspace}/users', [WorkspaceUserController::class, 'index'])->name('workspaces.users.index');
    Route::post('workspaces/{workspace}/users', [WorkspaceUserController::class, 'store'])->name('workspaces.users.store');
    Route::patch('workspaces/{workspace}/users/{targetUser}', [WorkspaceUserController::class, 'update'])->name('workspaces.users.update');
    Route::delete('workspaces/{workspace}/users/{targetUser}', [WorkspaceUserController::class, 'destroy'])->name('workspaces.users.destroy');

    Route::get('workspaces/{workspace}/tasks', [TaskController::class, 'index'])->name('workspaces.tasks.index');
    Route::post('workspaces/{workspace}/tasks', [TaskController::class, 'store'])->name('workspaces.tasks.store');
    Route::post('workspaces/{workspace}/tasks/{task}/retry', [TaskController::class, 'retry'])->name('workspaces.tasks.retry');
    Route::get('workspaces/{workspace}/tasks/{task}/download', [TaskController::class, 'download'])->name('workspaces.tasks.download');
    Route::get('workspaces/{workspace}/tasks/{task}/download/signed', [TaskController::class, 'downloadSigned'])
        ->middleware('signed')
        ->name('workspaces.tasks.download.signed');
});
