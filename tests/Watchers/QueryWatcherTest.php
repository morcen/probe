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

it('redacts bound values for sensitive columns', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'update users set password = ?, remember_token = ? where id = ?',
        ['new-hash', 'secret-token-value', 42],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("update users set password = '[redacted]', remember_token = '[redacted]' where id = 42");
});

it('redacts sensitive bound values in an INSERT statement', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'insert into users (name, email, password) values (?, ?, ?)',
        ['Jane Doe', 'jane@example.com', 'super-secret-hash'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("insert into users (name, email, password) values ('Jane Doe', 'jane@example.com', '[redacted]')");
});

it('redacts credit_card and ssn bound values by default', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'insert into payments (user_id, credit_card, ssn) values (?, ?, ?)',
        [1, '4111111111111111', '123-45-6789'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("insert into payments (user_id, credit_card, ssn) values (1, '[redacted]', '[redacted]')");
});

it('redacts sensitive bound values in an INSERT IGNORE INTO statement', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'insert ignore into users (name, email, password) values (?, ?, ?)',
        ['Jane Doe', 'jane@example.com', 'super-secret-hash'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("insert ignore into users (name, email, password) values ('Jane Doe', 'jane@example.com', '[redacted]')");
});

it('redacts sensitive bound values in a REPLACE INTO statement', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'replace into users (name, email, password) values (?, ?, ?)',
        ['Jane Doe', 'jane@example.com', 'super-secret-hash'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("replace into users (name, email, password) values ('Jane Doe', 'jane@example.com', '[redacted]')");
});

it('redacts sensitive bound values across multi-row INSERT statements', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'insert into users (name, password) values (?, ?), (?, ?)',
        ['Jane Doe', 'secret-one', 'John Doe', 'secret-two'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("insert into users (name, password) values ('Jane Doe', '[redacted]'), ('John Doe', '[redacted]')");
});

it('redacts sensitive bound values inside an IN (...) clause', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'select * from users where api_token in (?, ?)',
        ['token-one', 'token-two'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("select * from users where api_token in ('[redacted]', '[redacted]')");
});

it('redacts sensitive bound values inside a NOT IN (...) clause', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'select * from users where token not in (?, ?)',
        ['token-one', 'token-two'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("select * from users where token not in ('[redacted]', '[redacted]')");
});

it('redacts sensitive bound values inside a BETWEEN ? AND ? clause', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'select * from users where token between ? and ?',
        ['token-start', 'token-end'],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("select * from users where token between '[redacted]' and '[redacted]'");
});

it('does not redact non-sensitive bound values inside an IN (...) clause', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted(
        'select * from users where id in (?, ?)',
        [1, 2],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])->toBe('select * from users where id in (1, 2)');
});

it('does not redact non-sensitive bound values', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    event(new QueryExecuted('select * from users where id = ?', [1], 10.5, app('db')->connection()));

    expect($captured['content']['sql'])->toBe('select * from users where id = 1');
});

it('does not crash on a binding without a __toString method', function () {
    $captured = null;

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$captured) {
        $captured = $entry;
        return 1;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    // Plain DateTime (unlike Carbon) has no __toString(); casting it to a
    // string throws a fatal Error, previously uncaught because it happened
    // before record()'s try/catch was ever entered.
    event(new QueryExecuted(
        'select * from logs where created_at > ?',
        [new DateTime('2024-01-01')],
        5.0,
        app('db')->connection()
    ));

    expect($captured['content']['sql'])
        ->toBe("select * from logs where created_at > '[unrepresentable]'");
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

it('clears the n+1 detection map via resetPerRequestState()', function () {
    $ids = [];

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->andReturnUsing(function () use (&$ids) {
        $id = count($ids) + 1;
        $ids[] = $id;
        return $id;
    });

    // If resetPerRequestState() actually clears the tracking map, each
    // batch of 4 identical queries crosses the threshold (3) on its own
    // 4th query and tags all 4 of that batch's ids together — the same
    // shape as a single, un-reset batch of 4. Without the reset, the
    // second batch would already be above the threshold from the first
    // batch and would instead tag one id at a time.
    $storage->shouldReceive('addTagToIds')
        ->twice()
        ->with(Mockery::on(fn ($arg) => count($arg) === 4), 'n1');

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    for ($i = 0; $i < 4; $i++) {
        event(new QueryExecuted('select * from posts where user_id = ?', [1], 5.0, app('db')->connection()));
    }

    $watcher->resetPerRequestState();

    for ($i = 0; $i < 4; $i++) {
        event(new QueryExecuted('select * from posts where user_id = ?', [1], 5.0, app('db')->connection()));
    }
});

it('caps queryMap growth via max_tracked_fingerprints, evicting the oldest fingerprint', function () {
    config()->set('probe.watchers_config.queries.n1_threshold', 1);
    config()->set('probe.watchers_config.queries.max_tracked_fingerprints', 2);

    $ids = [];

    $storage = Mockery::mock(StorageDriverInterface::class);
    $storage->shouldReceive('store')->andReturnUsing(function () use (&$ids) {
        $id = count($ids) + 1;
        $ids[] = $id;
        return $id;
    });

    $tagCalls = [];
    $storage->shouldReceive('addTagToIds')->andReturnUsing(function (array $ids) use (&$tagCalls) {
        $tagCalls[] = $ids;
    });

    $watcher = new QueryWatcher($storage);
    $watcher->register();

    // Two distinct fingerprints fill the cap of 2 ('a' tracked first, then 'b').
    event(new QueryExecuted('select * from a', [], 5.0, app('db')->connection())); // id 1
    event(new QueryExecuted('select * from b', [], 5.0, app('db')->connection())); // id 2

    // A third distinct fingerprint pushes the map over the cap, evicting 'a'.
    event(new QueryExecuted('select * from c', [], 5.0, app('db')->connection())); // id 3

    // 'a' is queried again. Since it was evicted, this is tracked as a brand
    // new fingerprint (and evicts 'b') rather than resuming its prior count —
    // without the cap this would already be 'a''s 2nd occurrence and would
    // cross the n1 threshold (1) here.
    event(new QueryExecuted('select * from a', [], 5.0, app('db')->connection())); // id 4

    expect($tagCalls)->toBe([]);

    // A genuine 2nd occurrence of 'a' since its eviction crosses the threshold.
    event(new QueryExecuted('select * from a', [], 5.0, app('db')->connection())); // id 5

    expect($tagCalls)->toHaveCount(1)
        ->and($tagCalls[0])->toBe([4, 5]);
});
