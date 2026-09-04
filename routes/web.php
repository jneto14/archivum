<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home');

// What lets the app be installed to a home screen. Outside `auth` because both
// are read before anyone signs in — a manifest that redirects to the login page
// is read as HTML and the install option never appears — and at the root of the
// installation because a service worker may only claim the directory it is
// served from. See App\Http\Controllers\PwaController.
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/invitations.php';
require __DIR__ . '/workspaces.php';
require __DIR__ . '/organization.php';
require __DIR__ . '/documents.php';
require __DIR__ . '/attachments.php';
require __DIR__ . '/capture-sessions.php';
require __DIR__ . '/capture.php';
