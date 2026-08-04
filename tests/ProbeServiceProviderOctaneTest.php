<?php

// Minimal stand-in for Laravel Octane's RequestReceived event. The real
// laravel/octane package isn't a dependency of this package, so this stub
// lets ProbeServiceProvider's `class_exists(\Laravel\Octane\Events\RequestReceived::class)`
// branch be exercised here without pulling in the whole package.
namespace Laravel\Octane\Events {
    if (! class_exists(RequestReceived::class)) {
        class RequestReceived
        {
        }
    }
}

namespace {

    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Support\Facades\DB;
    use Laravel\Octane\Events\RequestReceived;
    use Morcen\Probe\ProbeServiceProvider;

    /**
     * bootWatchers() no-ops until the `probe_entries` table exists, and the
     * table isn't migrated in yet the moment service providers boot in this
     * suite (RefreshDatabase migrates after boot). Re-run the provider's
     * boot steps here, now that the table is in place, so these tests
     * exercise the real registration/reset code paths in
     * ProbeServiceProvider rather than a re-implementation of them.
     */
    function bootProbeWatchersForTest(): ProbeServiceProvider
    {
        $provider = app()->getProvider(ProbeServiceProvider::class);

        $bootWatchers = new ReflectionMethod($provider, 'bootWatchers');
        $bootWatchers->setAccessible(true);
        $bootWatchers->invoke($provider);

        $registerOctaneListeners = new ReflectionMethod($provider, 'registerOctaneListeners');
        $registerOctaneListeners->setAccessible(true);
        $registerOctaneListeners->invoke($provider);

        return $provider;
    }

    /**
     * Counts probe_entries rows via a raw PDO query instead of the query
     * builder — the query builder's own SELECT would itself pass through
     * QueryWatcher and add a row, skewing the very count being measured.
     */
    function countProbeEntries(): int
    {
        return (int) DB::connection()->getPdo()
            ->query('select count(*) as aggregate from probe_entries')
            ->fetchColumn();
    }

    it('does not stack duplicate watcher listeners when Octane reuses the worker across requests', function () {
        config()->set('probe.sampling_rate', 1.0);

        bootProbeWatchersForTest();

        // Simulate Octane serving several requests on the same long-running
        // worker process. Previously each of these re-ran bootWatchers(),
        // attaching another full set of watcher listeners on top of the
        // existing ones every time.
        event(new RequestReceived());
        event(new RequestReceived());
        event(new RequestReceived());

        $before = countProbeEntries();

        event(new QueryExecuted('select * from users where id = ?', [1], 5.0, app('db')->connection()));

        // Exactly one listener should still be recording — one query event
        // must produce exactly one entry, not one per accumulated listener.
        expect(countProbeEntries())->toBe($before + 1);
    });

    it('resets QueryWatcher n+1 tracking between Octane requests instead of leaking it across them', function () {
        config()->set('probe.watchers_config.queries.n1_threshold', 1);
        config()->set('probe.sampling_rate', 1.0);

        bootProbeWatchersForTest();

        $connection = app('db')->connection();

        // "Request 1": a single query for this fingerprint — below the n+1 threshold.
        event(new QueryExecuted('select * from posts where id = ?', [1], 5.0, $connection));

        // Octane reuses the worker for a new request; per-request watcher
        // state must reset here instead of carrying over.
        event(new RequestReceived());

        // "Request 2": one more query for the *same* fingerprint. If the n+1
        // map had leaked across requests, this would be its 2nd hit overall
        // and would wrongly cross the threshold.
        event(new QueryExecuted('select * from posts where id = ?', [1], 5.0, $connection));

        expect(DB::table('probe_entries')->where('tags', 'like', '%n1%')->count())->toBe(0);
    });
}
