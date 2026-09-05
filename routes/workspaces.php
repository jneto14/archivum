<?php

declare(strict_types=1);

use App\Http\Controllers\Workspaces\ActivityController;
use App\Http\Controllers\Workspaces\IntakeLabelController;
use App\Http\Controllers\Workspaces\TaskController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use App\Http\Controllers\Workspaces\WorkspaceLimitController;
use App\Http\Controllers\Workspaces\WorkspaceSettingsController;
use App\Http\Controllers\Workspaces\WorkspaceSwitchController;
use App\Http\Controllers\Workspaces\WorkspaceUsageController;
use App\Http\Controllers\Workspaces\WorkspaceUserController;
use App\Http\Middleware\DenyInDemoMode;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workspaces
|--------------------------------------------------------------------------
|
| Three of these carry DenyInDemoMode. The demo account is a platform admin,
| so a visitor holding the credentials from the login screen can reach all of
| them: deleting the workspace empties the demo, creating them is unbounded
| and leaves the next visitor a switcher full of strangers' names, and the
| limits are what stop an upload spree filling the volume before the nightly
| reset — raising them removes the only ceiling there is.
|
| Renaming, membership and everything below is left alone: it is the product,
| the reset undoes it, and RemoveWorkspaceUser already refuses to leave a
| workspace with no admin.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->middleware(DenyInDemoMode::class)
        ->name('workspaces.store');
    Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
        ->middleware(DenyInDemoMode::class)
        ->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/switch', [WorkspaceSwitchController::class, 'store'])->name('workspaces.switch');
    Route::get('workspaces/{workspace}/usage', [WorkspaceUsageController::class, 'show'])->name('workspaces.usage');
    Route::patch('workspaces/{workspace}/limits', [WorkspaceLimitController::class, 'update'])
        ->middleware(DenyInDemoMode::class)
        ->name('workspaces.limits.update');
    Route::get('workspaces/{workspace}/settings', [WorkspaceSettingsController::class, 'show'])->name('workspaces.settings.show');

    // Answering a label the archive taught itself. Accept, reject, and retire
    // an accepted one are the same write — see IntakeLabelController.
    Route::patch('workspaces/{workspace}/intake-labels/{intakeLabel}', [IntakeLabelController::class, 'update'])
        ->name('workspaces.intake-labels.update');

    Route::get('workspaces/{workspace}/users', [WorkspaceUserController::class, 'index'])->name('workspaces.users.index');
    Route::post('workspaces/{workspace}/users', [WorkspaceUserController::class, 'store'])->name('workspaces.users.store');
    Route::patch('workspaces/{workspace}/users/{targetUser}', [WorkspaceUserController::class, 'update'])->name('workspaces.users.update');
    Route::delete('workspaces/{workspace}/users/{targetUser}', [WorkspaceUserController::class, 'destroy'])->name('workspaces.users.destroy');

    Route::get('workspaces/{workspace}/tasks', [TaskController::class, 'index'])->name('workspaces.tasks.index');
    Route::post('workspaces/{workspace}/tasks', [TaskController::class, 'store'])->name('workspaces.tasks.store');
    Route::post('workspaces/{workspace}/tasks/{task}/retry', [TaskController::class, 'retry'])->name('workspaces.tasks.retry');
    Route::get('workspaces/{workspace}/tasks/{task}/download', [TaskController::class, 'download'])->name('workspaces.tasks.download');
    // `relative` so the signature does not cover the host or the path prefix,
    // which a proxy serving this under a path strips before the request lands.
    // Paired with App\Support\SignedLink, which builds the link the same way.
    Route::get('workspaces/{workspace}/tasks/{task}/download/signed', [TaskController::class, 'downloadSigned'])
        ->middleware('signed:relative')
        ->name('workspaces.tasks.download.signed');

    Route::get('workspaces/{workspace}/activity', [ActivityController::class, 'index'])->name('workspaces.activity.index');
});
