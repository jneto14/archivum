<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

/*
| The two vocabularies a workspace files by. Neither listing is paginated: a
| workspace has a handful of each, and a client mapping its own vocabulary onto
| this one wants the whole set in a single answer.
|
| The parameter names matter — the Form Requests these share with the web
| routes read `{workspace}`, `{documentType}` and `{tag}` off the route to
| scope their uniqueness rules.
*/
Route::get('workspaces/{workspace}/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
Route::post('workspaces/{workspace}/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');

Route::get('workspaces/{workspace}/tags', [TagController::class, 'index'])->name('tags.index');
Route::post('workspaces/{workspace}/tags', [TagController::class, 'store'])->name('tags.store');
Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
