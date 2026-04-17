<?php

namespace Morcen\Probe\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProbeAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        // Use the application's gate if a custom authorization callback is set.
        $gate = app('probe.auth', null);

        if ($gate instanceof Closure && ! $gate($request)) {
            abort(403, 'Unauthorized.');
        }

        // In non-local environments, require an explicit auth callback.
        if ($gate === null && ! app()->environment('local')) {
            abort(403, 'Probe is not accessible in this environment. Register a Probe::auth() callback.');
        }

        return $next($request);
    }
}
