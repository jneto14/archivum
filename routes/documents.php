<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentMoveController;
use App\Http\Controllers\Documents\DocumentSearchController;
use App\Http\Controllers\Documents\DocumentTypeController;
use App\Http\Controllers\Documents\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces/{workspace}/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('workspaces/{workspace}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('workspaces/{workspace}/documents/create', [DocumentController::class, 'create'])->name('documents.create');
    Route::get('workspaces/{workspace}/documents/search', [DocumentSearchController::class, 'index'])->name('documents.search');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
    Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('documents/{document}/move', [DocumentMoveController::class, 'store'])->name('documents.move');

    Route::get('workspaces/{workspace}/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
    Route::post('workspaces/{workspace}/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
    Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
    Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');

    Route::get('workspaces/{workspace}/tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('workspaces/{workspace}/tags', [TagController::class, 'store'])->name('tags.store');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
});
