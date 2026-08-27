<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Share the visitor's appearance (theme) cookie with all views, defaulting to "system".
     *
     * @param  Request  $request  The incoming request, read for its `appearance` cookie.
     * @param  Closure(Request): (Response)  $next
     * @return Response The response returned by the next middleware/handler in the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
