<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentVersionController;
use Illuminate\Support\Facades\Route;

/*
| An attachment's history is reachable two ways, and deliberately so: inline on
| the attachment, for a client reading one; and here, for a client walking the
| chain. A version is addressed directly for the same reason the interface
| addresses it that way — the history is a list of these, and each row's
| actions act on one of them.
*/
Route::get('attachments/{attachment}/versions', [AttachmentVersionController::class, 'index'])->name('attachments.versions.index');
Route::get('attachment-versions/{version}/file', [AttachmentVersionController::class, 'download'])->name('attachment-versions.file');
Route::post('attachment-versions/{version}/restore', [AttachmentVersionController::class, 'restore'])->name('attachment-versions.restore');
