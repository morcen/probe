<?php

use Illuminate\Support\Facades\Cache;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\CacheWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
    config()->set('cache.default', 'array');
});

// Cache events are dispatched by the real repository rather than constructed
// by hand: their constructor signatures differ between Laravel 10 and 11+
// (11 added $storeName as the first argument).

it('records a cache hit', function () {
    Cache::put('user.1', 'value', 60);

    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    Cache::get('user.1');

    expect($captured['content']['event'])->toBe('hit')
        ->and($captured['content']['key'])->toBe('user.1')
        ->and($captured['tags'])->toContain('hit');
});

it('records a cache miss', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    Cache::get('missing.key');

    expect($captured['content']['event'])->toBe('miss');
});

it('records a key write', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    Cache::put('user.1', 'value', 3600);

    expect($captured['content']['event'])->toBe('write')
        ->and($captured['content']['seconds'])->toBe(3600);
});

it('records a key forgotten', function () {
    Cache::put('user.1', 'value', 60);

    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    Cache::forget('user.1');

    expect($captured['content']['event'])->toBe('forget');
});
