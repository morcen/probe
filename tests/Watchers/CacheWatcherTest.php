<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\CacheWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
});

it('records a cache hit', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    event(new CacheHit('default', 'user.1', 'value'));

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

    event(new CacheMissed('default', 'missing.key'));

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

    event(new KeyWritten('default', 'user.1', 'value', 3600));

    expect($captured['content']['event'])->toBe('write')
        ->and($captured['content']['seconds'])->toBe(3600);
});

it('records a key forgotten', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new CacheWatcher($storage);
    $watcher->register();

    event(new KeyForgotten('default', 'user.1'));

    expect($captured['content']['event'])->toBe('forget');
});
