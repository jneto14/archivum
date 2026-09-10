<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('documents/{document}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::delete('attachments/{attachment}/duplicate', [AttachmentController::class, 'dismissDuplicate'])->name('attachments.duplicate.dismiss');
    Route::post('attachments/{attachment}/extraction', [AttachmentController::class, 'reextract'])->name('attachments.extraction.store');
    Route::post('attachments/{attachment}/reading', [AttachmentController::class, 'confirmOcr'])->name('attachments.reading.confirm');
    Route::delete('attachments/{attachment}/reading', [AttachmentController::class, 'rejectOcr'])->name('attachments.reading.reject');
});
