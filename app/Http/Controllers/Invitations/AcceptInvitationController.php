<?php

namespace App\Http\Controllers\Invitations;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    /**
     * Display the accept-invitation form for the given invitation token.
     */
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $request->query('email'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }
}
