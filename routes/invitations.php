<?php

use App\Http\Controllers\Invitations\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:web')->get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.accept');
