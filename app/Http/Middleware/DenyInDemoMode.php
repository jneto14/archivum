<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an action that a visitor to a public demo must not be able to take.
 *
 * The credentials are printed on the login screen and the demo account is both
 * a workspace admin and a platform admin, so every visitor arrives holding the
 * keys to the installation. What has to be stopped is not damage — the nightly
 * reset repairs everything — but the hours between: a visitor who deletes the
 * workspace, changes the account's email or raises the storage limits leaves
 * the demo useless to whoever arrives next, and that may be twenty-three hours.
 *
 * Applied per route, in `routes/`, rather than checked inside the controllers.
 * Which actions a demo forbids is a list worth being able to read in one place;
 * scattered through controllers it becomes a list nobody can see the end of.
 *
 * Sent back as a redirect with a validation error rather than a 403, because
 * this arrives from a form: the person should see why the button did nothing,
 * on the page they were already on. The affordances are also hidden in demo
 * mode, so reaching this is either a stale page or somebody trying it directly.
 */
class DenyInDemoMode
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!DemoMode::enabled()) {
            return $next($request);
        }

        return back()->withErrors([
            'demo' => __('demo.action_unavailable'),
        ]);
    }
}
