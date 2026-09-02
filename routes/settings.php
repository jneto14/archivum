<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\DenyInDemoMode;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    /*
     * Deleting the account is the password restriction's twin: the credentials
     * on the demo's login screen stop working, and nobody can sign in again
     * until the nightly reset seeds the account back.
     */
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware(DenyInDemoMode::class)
        ->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware(['throttle:6,1', DenyInDemoMode::class])
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::post('settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
