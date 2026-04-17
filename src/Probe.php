<?php

namespace Morcen\Probe;

use Closure;

class Probe
{
    /**
     * Register the authorization callback for the Probe dashboard.
     *
     * Example in AppServiceProvider:
     *
     *   Probe::auth(function ($request) {
     *       return $request->user()?->isAdmin();
     *   });
     */
    public static function auth(Closure $callback): void
    {
        app()->instance('probe.auth', $callback);
    }
}
