<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     *
     * @param Request $request The incoming request, used to resolve the current user and session status.
     *
     * @return Response The Inertia response rendering the profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'locales' => config('archivum.locales'),
            /**
             * Sourced from the same DateTimeZone identifier list the
             * "timezone" validation rule checks against (see
             * ProfileValidationRules::timezoneRules()), rather than the
             * browser's own Intl.supportedValuesOf('timeZone') — the two
             * can disagree on renamed identifiers (e.g. a browser still
             * offering "Europe/Kiev", which PHP's bundled tzdata already
             * only recognizes as "Europe/Kyiv"), letting the user pick an
             * option the backend then rejects.
             */
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * @param ProfileUpdateRequest $request The incoming request with the validated profile attributes.
     *
     * @return RedirectResponse Redirect back to the profile edit page.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('profile.updated')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     *
     * @param ProfileDeleteRequest $request The incoming request; validates the current password before deletion.
     *
     * @return RedirectResponse Redirect to the application root.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
