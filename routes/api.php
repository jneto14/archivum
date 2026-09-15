<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Predates the versioned API and stays where it is. docs/api.md has described
| it as the one route on offer for long enough that something may be calling
| it, and it costs nothing to keep. `/api/v1/user` is the one to build against.
*/
Route::get('/user', fn (Request $request) => $request->user())
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('api.user');

/*
|--------------------------------------------------------------------------
| Version 1
|--------------------------------------------------------------------------
|
| Token-authenticated, and independent of the Inertia layer: the two share
| actions and policies, never response shapes. A page prop can change with the
| page that reads it; this cannot (ARC-121).
|
| The workspace is named in the URL rather than resolved from a session, the
| way the web routes already address it. ResolveWorkspace is web-group
| middleware reading `current_workspace_id` and is not in this pipeline, which
| is why `workspaces.switch` has no equivalent here — it is a session write,
| not a capability.
|
*/

Route::prefix('v1')
    ->as('api.v1.')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function (): void {
        Route::get('/user', [UserController::class, 'show'])->name('user.show');

        require __DIR__ . '/api/v1/documents.php';
        require __DIR__ . '/api/v1/vocabulary.php';
        require __DIR__ . '/api/v1/attachments.php';
        require __DIR__ . '/api/v1/attachment-versions.php';
        require __DIR__ . '/api/v1/trash.php';
        require __DIR__ . '/api/v1/workspaces.php';
    });
