<?php

declare(strict_types=1);

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
     *
     * @param Request $request The incoming request; its `email` query parameter pre-fills the form.
     * @param string $token The invitation/password-reset token to submit alongside the new password.
     *
     * @return Response The Inertia response rendering the accept-invitation page.
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
