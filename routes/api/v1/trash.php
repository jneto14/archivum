<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TrashController;
use Illuminate\Support\Facades\Route;

/*
| Two listings rather than the interface's single page carrying both: a page
| can hold two paginators side by side, a response cannot say which `meta`
| belongs to which half.
|
| The ids are plain strings, not bound models. These rows are hidden by the
| soft-delete scope, so route model binding would 404 on every one of them.
*/
Route::get('workspaces/{workspace}/trash/documents', [TrashController::class, 'documents'])->name('trash.documents.index');
Route::get('workspaces/{workspace}/trash/attachments', [TrashController::class, 'attachments'])->name('trash.attachments.index');

Route::post('workspaces/{workspace}/trash/documents/{document}', [TrashController::class, 'restoreDocument'])->name('trash.documents.restore');
Route::delete('workspaces/{workspace}/trash/documents/{document}', [TrashController::class, 'purgeDocument'])->name('trash.documents.purge');

Route::post('workspaces/{workspace}/trash/attachments/{attachment}', [TrashController::class, 'restoreAttachment'])->name('trash.attachments.restore');
Route::delete('workspaces/{workspace}/trash/attachments/{attachment}', [TrashController::class, 'purgeAttachment'])->name('trash.attachments.purge');

Route::delete('workspaces/{workspace}/trash', [TrashController::class, 'empty'])->name('trash.empty');
