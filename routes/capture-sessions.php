<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\CaptureSessionController;
use Illuminate\Support\Facades\Route;

// The authenticated, desktop-facing half of mobile capture: starting a
// pairing session, fetching its QR code, and cancelling it. Its live status
// is not polled here — the document show page reloads its own
// `activeCaptureSession` prop instead (see DocumentController::show()). The
// session's own signed URL (routes/capture.php) is the unauthenticated half
// the phone actually uses.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('documents/{document}/capture-sessions', [CaptureSessionController::class, 'store'])
        ->name('capture-sessions.store');
    Route::get('documents/{document}/capture-sessions/{captureSession}/qr-code', [CaptureSessionController::class, 'qrCode'])
        ->name('capture-sessions.qr-code');
    Route::post('documents/{document}/capture-sessions/{captureSession}/cancel', [CaptureSessionController::class, 'cancel'])
        ->name('capture-sessions.cancel');
});
