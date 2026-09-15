<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CaptureSessionController;
use App\Http\Controllers\Api\V1\IntakeReviewController;
use App\Http\Controllers\Api\V1\MetadataSuggestionController;
use Illuminate\Support\Facades\Route;

/*
| What the archive worked out on its own, and the answers to it.
*/
Route::get('workspaces/{workspace}/review', [IntakeReviewController::class, 'index'])->name('documents.review.index');
Route::post('workspaces/{workspace}/review', [IntakeReviewController::class, 'store'])->name('documents.review.bulk');

Route::get('documents/{document}/metadata-suggestions', [IntakeReviewController::class, 'suggestions'])->name('documents.suggestions.index');
Route::post('documents/{document}/metadata-suggestions', [MetadataSuggestionController::class, 'store'])->name('documents.suggestions.accept');

/*
| The desktop half of mobile capture. The phone's half stays outside the API
| and outside authentication — a phone scanning a code has no session and never
| will — so what this hands back is the signed URL the phone acts on. The
| interface turns that into a QR code; a client is given the URL itself.
*/
Route::post('documents/{document}/capture-sessions', [CaptureSessionController::class, 'store'])->name('capture-sessions.store');
Route::get('documents/{document}/capture-sessions/{captureSession}', [CaptureSessionController::class, 'show'])->name('capture-sessions.show');
Route::post('documents/{document}/capture-sessions/{captureSession}/cancel', [CaptureSessionController::class, 'cancel'])->name('capture-sessions.cancel');
