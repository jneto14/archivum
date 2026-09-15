<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentMoveController;
use Illuminate\Support\Facades\Route;

/*
| There is no separate search route. `q` and `mode` are filters on the listing,
| the way the interface's own index treats them, so there is no second surface
| to keep in step with this one.
*/
Route::get('workspaces/{workspace}/documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('workspaces/{workspace}/documents', [DocumentController::class, 'store'])->name('documents.store');

Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
Route::post('documents/{document}/move', [DocumentMoveController::class, 'store'])->name('documents.move');
