<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiTokenController extends Controller
{
    /**
     * Issue a new personal access token for the current user.
     *
     * @param StoreApiTokenRequest $request The incoming request with the validated token name.
     *
     * @return RedirectResponse Redirect back to the previous page, flashing the plain-text token for one-time display.
     */
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken($request->validated('name'));

        Inertia::flash('newApiToken', $token->plainTextToken);

        return back();
    }

    /**
     * Revoke one of the current user's personal access tokens.
     *
     * @param Request $request The incoming request, used to resolve the current user.
     * @param int $token The ID of the token to revoke; resolved through the user's own tokens so one user can never affect another's.
     *
     * @return RedirectResponse Redirect back to the previous page.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        return back();
    }
}
