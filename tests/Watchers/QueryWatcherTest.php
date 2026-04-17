<?php

use Illuminate\Database\Events\QueryExecuted;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\QueryWatcher;

beforeEach(function () {
    config()->set('probe.sampling_rate', 1.0);
    config()->set('probe.watchers_config.queries.slow_threshold', 100);
    config()->set('probe.watchers_config.queries.n1_threshold', 3);
});

it('records a query entry', function () {
    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturn(1);

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted('select * from users where id = ?', [1], 10.5, app('db')->connection()));
});

it('tags a slow query', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted('select * from users', [], 250.0, app('db')->connection()));

    expect($captured['tags'])->toContain('slow');
});

it('does not tag a fast query as slow', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted('select * from users', [], 10.0, app('db')->connection()));

    expect($captured['tags'])->not->toContain('slow');
});

it('detects n+1 queries and tags entries', function () {
    $ids = [];

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->andReturnUsing(function () use (&$ids) {
        $id = count($ids) + 1;
        $ids[] = $id;
        return $id;
    });

    // Expect addTagToIds to be called once the threshold (3) is crossed on the 4th identical query.
    $storage->shouldReceive('addTagToIds')
        ->once()
        ->with(Mockery::on(fn ($arg) => count($arg) === 4), 'n1');

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    // Fire 4 identical queries (same fingerprint) — threshold is 3, so 4th triggers n1 tagging.
    for ($i = 0; $i < 4; $i++) {
        event(new QueryExecuted('select * from posts where user_id = ?', [1], 5.0, app('db')->connection()));
    }
});
