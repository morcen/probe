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
        $gate = app()->bound('probe.auth') ? app('probe.auth') : null;

        if ($gate !== null) {
            // Fail closed: only a Closure is a recognized gate. Anything else
            // (e.g. a class binding instead of Probe::auth()) must not grant access.
            if (! $gate instanceof Closure || ! $gate($request)) {
                abort(403, 'Unauthorized.');
            }

            return $next($request);
        }

        // In non-local environments, require an explicit auth callback.
        if (! app()->environment('local')) {
            abort(403, 'Probe is not accessible in this environment. Register a Probe::auth() callback.');
        }

        return $next($request);
    }
}
