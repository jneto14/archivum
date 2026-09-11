<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\AttachmentController;
use App\Http\Controllers\Documents\AttachmentVersionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('documents/{document}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::post('attachments/{attachment}/file', [AttachmentController::class, 'replace'])->name('attachments.file.replace');
    Route::get('attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::delete('attachments/{attachment}/duplicate', [AttachmentController::class, 'dismissDuplicate'])->name('attachments.duplicate.dismiss');
    Route::post('attachments/{attachment}/extraction', [AttachmentController::class, 'reextract'])->name('attachments.extraction.store');
    Route::post('attachments/{attachment}/reading', [AttachmentController::class, 'confirmOcr'])->name('attachments.reading.confirm');
    Route::delete('attachments/{attachment}/reading', [AttachmentController::class, 'rejectOcr'])->name('attachments.reading.reject');

    // The files an attachment used to hold. Addressed by the version rather
    // than through its attachment, because that is all the interface has in
    // hand — the history is a list of these, and each row's two actions act on
    // one of them.
    Route::get('attachment-versions/{version}', [AttachmentVersionController::class, 'show'])->name('attachment-versions.show');
    Route::post('attachment-versions/{version}/restore', [AttachmentVersionController::class, 'restore'])->name('attachment-versions.restore');
});
