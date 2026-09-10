<?php

declare(strict_types=1);

use App\Http\Controllers\Workspaces\TrashController;
use Illuminate\Support\Facades\Route;

/*
| The trash acts on rows the models' default scope hides, so these routes take
| the id as a plain string and resolve it in the controller with
| `onlyTrashed()`. Route model binding would run the ordinary lookup and 404
| on every one of them.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces/{workspace}/trash', [TrashController::class, 'index'])->name('trash.index');
    Route::delete('workspaces/{workspace}/trash', [TrashController::class, 'empty'])->name('trash.empty');

    Route::post('workspaces/{workspace}/trash/documents/{document}', [TrashController::class, 'restoreDocument'])->name('trash.documents.restore');
    Route::delete('workspaces/{workspace}/trash/documents/{document}', [TrashController::class, 'purgeDocument'])->name('trash.documents.purge');

    Route::post('workspaces/{workspace}/trash/attachments/{attachment}', [TrashController::class, 'restoreAttachment'])->name('trash.attachments.restore');
    Route::delete('workspaces/{workspace}/trash/attachments/{attachment}', [TrashController::class, 'purgeAttachment'])->name('trash.attachments.purge');
});
