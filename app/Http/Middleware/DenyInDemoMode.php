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
 * Applied to password changes. Anyone can sign in to a demo with the
 * credentials printed on the login screen, so the first visitor to change the
 * password would lock every later visitor out until the next reset — turning a
 * demo into a private installation for whoever got there first.
 *
 * Sent back as a redirect with a validation error rather than a 403, because
 * this arrives from a form: the person should see why the button did nothing,
 * on the page they were already on.
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
