<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use Illuminate\Support\Facades\Route;

/*
| One divergence from the web routes, deliberate: there, GET on an attachment
| is the download, because a browser following a link wants the file. Here it
| is the metadata, and the bytes are at `/file`. A client wants to know what it
| is about to fetch — how big, what type, what the reading says — without
| fetching it.
*/
Route::get('documents/{document}/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
Route::post('documents/{document}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');

Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
Route::get('attachments/{attachment}/file', [AttachmentController::class, 'download'])->name('attachments.file');
Route::get('attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
Route::post('attachments/{attachment}/file', [AttachmentController::class, 'replace'])->name('attachments.file.replace');
Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
Route::delete('attachments/{attachment}/duplicate', [AttachmentController::class, 'dismissDuplicate'])->name('attachments.duplicate.dismiss');
Route::post('attachments/{attachment}/extraction', [AttachmentController::class, 'reextract'])->name('attachments.extraction.store');
Route::post('attachments/{attachment}/reading', [AttachmentController::class, 'confirmReading'])->name('attachments.reading.confirm');
Route::delete('attachments/{attachment}/reading', [AttachmentController::class, 'rejectReading'])->name('attachments.reading.reject');
