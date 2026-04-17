<?php

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\RequestWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
    config()->set('probe.watchers_config.requests.ignore_paths', []);
    config()->set('probe.watchers_config.requests.ignore_status_codes', []);
});

it('records an entry when a request is handled', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(1);

    $watcher = new RequestWatcher($storage);
    $watcher->register();

    $request  = Request::create('/hello', 'GET');
    $response = new Response('OK', 200);

    event(new RequestHandled($request, $response));
});

it('does not record when the path is ignored', function () {
    config()->set('probe.watchers_config.requests.ignore_paths', ['probe/*']);

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new RequestWatcher($storage);
    $watcher->register();

    $request  = Request::create('/probe/entries', 'GET');
    $response = new Response('OK', 200);

    event(new RequestHandled($request, $response));
});

it('does not record when the status code is ignored', function () {
    config()->set('probe.watchers_config.requests.ignore_status_codes', [404]);

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new RequestWatcher($storage);
    $watcher->register();

    $request  = Request::create('/missing', 'GET');
    $response = new Response('Not Found', 404);

    event(new RequestHandled($request, $response));
});

it('redacts authorization header', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new RequestWatcher($storage);
    $watcher->register();

    $request = Request::create('/api/user', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer secret-token',
    ]);
    $response = new Response('{}', 200);

    event(new RequestHandled($request, $response));

    expect($captured['content']['headers']['authorization'])->toBe(['[redacted]']);
});

it('records nothing when sampling rate is 0', function () {
    config()->set('probe.sampling_rate', 0.0);

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldNotReceive('store');

    $watcher = new RequestWatcher($storage);
    $watcher->register();

    $request  = Request::create('/hello', 'GET');
    $response = new Response('OK', 200);

    event(new RequestHandled($request, $response));
});
