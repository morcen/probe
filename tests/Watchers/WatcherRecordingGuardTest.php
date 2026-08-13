<?php

// Minimal stand-in for ext-swoole's Coroutine class, mirroring the Octane
// event stub in ProbeServiceProviderOctaneTest.php. The real swoole
// extension isn't available in this suite, so this lets Watcher's
// coroutine-aware recordingKey() branch be exercised without it.
namespace Swoole {
    if (! class_exists(Coroutine::class)) {
        class Coroutine
        {
            public static int $cid = 0;

            public static function getCid(): int
            {
                return self::$cid;
            }
        }
    }
}

namespace {

    use Illuminate\Database\Events\QueryExecuted;
    use Morcen\Probe\Storage\StorageDriverInterface;
    use Morcen\Probe\Watchers\QueryWatcher;
    use Swoole\Coroutine;

    beforeEach(function () {
        config()->set('probe.sampling_rate', 1.0);
        Coroutine::$cid = 0;
    });

    afterEach(function () {
        Coroutine::$cid = 0;
    });

    it('does not drop a concurrent coroutine\'s entry while another coroutine is mid-store()', function () {
        $stored = [];

        $storage = Mockery::mock(StorageDriverInterface::class);
        $storage->shouldReceive('store')->twice()->andReturnUsing(function (array $entry) use (&$stored) {
            $stored[] = $entry;

            if (count($stored) === 1) {
                // Simulate Swoole scheduling a second, unrelated request's
                // coroutine while this one is still blocked inside store()
                // (e.g. yielding on the DB insert's I/O) — a same-process,
                // genuinely concurrent QueryExecuted event, not a recursive
                // re-entry of the first.
                Coroutine::$cid = 1;
                event(new QueryExecuted('select * from posts', [], 5.0, app('db')->connection()));
                Coroutine::$cid = 0;
            }

            return count($stored);
        });

        $watcher = new QueryWatcher($storage);
        $watcher->register();

        event(new QueryExecuted('select * from users', [], 5.0, app('db')->connection()));

        // Both the outer (coroutine 0) and interleaved (coroutine 1) queries
        // must be recorded. Previously, the single process-wide guard flag
        // would still be true when the interleaved event fired, so it would
        // be silently dropped (only 1 store() call) even though it wasn't
        // actually a recursive call from the same request.
        expect($stored)->toHaveCount(2)
            ->and(array_column($stored, 'type'))->toBe(['queries', 'queries']);
    });

    it('still guards against genuine re-entrant recording within the same coroutine', function () {
        $stored = [];

        $storage = Mockery::mock(StorageDriverInterface::class);
        $storage->shouldReceive('store')->once()->andReturnUsing(function (array $entry) use (&$stored) {
            $stored[] = $entry;

            // Same coroutine (cid unchanged) re-entering store() synchronously
            // — e.g. the INSERT itself firing another QueryExecuted event —
            // must still be suppressed to avoid infinite recursion.
            event(new QueryExecuted('select * from posts', [], 5.0, app('db')->connection()));

            return count($stored);
        });

        $watcher = new QueryWatcher($storage);
        $watcher->register();

        event(new QueryExecuted('select * from users', [], 5.0, app('db')->connection()));

        expect($stored)->toHaveCount(1);
    });
}
