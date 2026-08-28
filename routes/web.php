<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/invitations.php';
require __DIR__ . '/workspaces.php';
require __DIR__ . '/organization.php';
require __DIR__ . '/documents.php';
require __DIR__ . '/attachments.php';
