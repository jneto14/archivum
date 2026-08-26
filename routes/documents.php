<?php

use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentMoveController;
use App\Http\Controllers\Documents\DocumentTypeController;
use App\Http\Controllers\Documents\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('workspaces/{workspace}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('documents/{document}/move', [DocumentMoveController::class, 'store'])->name('documents.move');

    Route::post('workspaces/{workspace}/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
    Route::post('workspaces/{workspace}/tags', [TagController::class, 'store'])->name('tags.store');
});
