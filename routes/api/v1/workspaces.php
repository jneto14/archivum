<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\IntakeLabelController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceLimitController;
use App\Http\Middleware\DenyInDemoMode;
use Illuminate\Support\Facades\Route;

/*
| The listing is the first call a client makes: every other route names a
| workspace in its path, and a token carries no default one.
|
| DenyInDemoMode sits on the same three writes the web routes guard, and for
| the same reasons — the demo account is a platform admin, so a visitor holding
| the credentials from the login screen can reach all three. A token changes
| nothing about that except how easy the reach is.
*/
Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
Route::post('workspaces', [WorkspaceController::class, 'store'])
    ->middleware(DenyInDemoMode::class)
    ->name('workspaces.store');

Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('workspaces.show');
Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])
    ->middleware(DenyInDemoMode::class)
    ->name('workspaces.destroy');

Route::get('workspaces/{workspace}/usage', [WorkspaceController::class, 'usage'])->name('workspaces.usage');
Route::patch('workspaces/{workspace}/limits', [WorkspaceLimitController::class, 'update'])
    ->middleware(DenyInDemoMode::class)
    ->name('workspaces.limits.update');

Route::get('workspaces/{workspace}/intake-labels', [IntakeLabelController::class, 'index'])->name('workspaces.intake-labels.index');
Route::patch('workspaces/{workspace}/intake-labels/{intakeLabel}', [IntakeLabelController::class, 'update'])->name('workspaces.intake-labels.update');
