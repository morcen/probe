<?php

use Illuminate\Http\Request;
use Morcen\Probe\Http\Middleware\ProbeAuthentication;
use Symfony\Component\HttpKernel\Exception\HttpException;

function callProbeAuthentication(Request $request): int
{
    $middleware = new ProbeAuthentication();

    try {
        return $middleware->handle($request, fn ($req) => response('OK', 200))->getStatusCode();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

it('allows the request when the Closure gate returns true', function () {
    app()->instance('probe.auth', fn ($request) => true);

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(200);
});

it('denies the request when the Closure gate returns false', function () {
    app()->instance('probe.auth', fn ($request) => false);

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(403);
});

it('denies the request when probe.auth is bound to a non-Closure that denies access', function () {
    app()->instance('probe.auth', new class
    {
        public function __invoke($request)
        {
            return false;
        }
    });

    app()->detectEnvironment(fn () => 'production');

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(403);
});

it('fails closed when probe.auth is bound to a non-Closure that would otherwise allow access', function () {
    app()->instance('probe.auth', new class
    {
        public function __invoke($request)
        {
            return true;
        }
    });

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(403);
});

it('allows the request in the local environment when no gate is registered', function () {
    app()->instance('probe.auth', null);
    app()->detectEnvironment(fn () => 'local');

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(200);
});

it('denies the request in non-local environments when no gate is registered', function () {
    app()->instance('probe.auth', null);
    app()->detectEnvironment(fn () => 'production');

    expect(callProbeAuthentication(Request::create('/probe')))->toBe(403);
});
