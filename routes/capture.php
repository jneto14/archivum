<?php

declare(strict_types=1);

use App\Http\Controllers\Documents\CapturePageController;
use Illuminate\Support\Facades\Route;

// The phone-facing half of mobile capture. Deliberately outside `auth` — a
// phone scanning the QR code has no session of its own, and never will. The
// `signed` middleware is the access control here: both routes share one URI,
// so the exact same signed link the desktop showed as a QR code covers
// loading the page and every upload made from it. See
// CaptureSessionController::pairingUrl() for how the link is built, and
// DocumentCaptureSession::isActive() for what happens once its status or
// expiry says otherwise.
Route::middleware(['signed'])->group(function () {
    Route::get('capture/{captureSession}', [CapturePageController::class, 'show'])->name('capture.show');
    Route::post('capture/{captureSession}', [CapturePageController::class, 'store'])->name('capture.store');
});
