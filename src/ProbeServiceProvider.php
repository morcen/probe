<?php

namespace Morcen\Probe;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Morcen\Probe\Console\ClearCommand;
use Morcen\Probe\Console\InstallCommand;
use Morcen\Probe\Console\PruneCommand;
use Morcen\Probe\Http\Middleware\ProbeAuthentication;
use Morcen\Probe\Http\ProbeController;
use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;
use Morcen\Probe\Watchers\CacheWatcher;
use Morcen\Probe\Watchers\ExceptionWatcher;
use Morcen\Probe\Watchers\JobWatcher;
use Morcen\Probe\Watchers\QueryWatcher;
use Morcen\Probe\Watchers\RequestWatcher;
use Morcen\Probe\Watchers\ScheduleWatcher;

class ProbeServiceProvider extends ServiceProvider
{
    /**
     * Maps config watcher keys to their watcher classes.
     *
     * @var array<string, class-string>
     */
    private const WATCHER_MAP = [
        'requests'   => RequestWatcher::class,
        'exceptions' => ExceptionWatcher::class,
        'jobs'       => JobWatcher::class,
        'queries'    => QueryWatcher::class,
        'cache'      => CacheWatcher::class,
        'schedule'   => ScheduleWatcher::class,
    ];

    /**
     * Watcher instances booted for this process, kept so Octane can reset
     * their per-request state without re-registering event listeners.
     *
     * @var array<int, \Morcen\Probe\Watchers\Watcher>
     */
    private array $watchers = [];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/probe.php',
            'probe'
        );

        $this->app->singleton(StorageDriverInterface::class, function () {
            return match (config('probe.storage_driver', 'database')) {
                default => new DatabaseDriver(),
            };
        });

        // Default no-op auth binding — replaced by Probe::auth() calls.
        if (! $this->app->bound('probe.auth')) {
            $this->app->instance('probe.auth', null);
        }
    }

    public function boot(): void
    {
        $this->registerPublishables();
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'probe');

        if (! config('probe.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->bootWatchers();
        $this->registerRoutes();
        $this->registerOctaneListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PruneCommand::class,
                ClearCommand::class,
            ]);
        }
    }

    private function bootWatchers(): void
    {
        try {
            if (! Schema::hasTable('probe_entries')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $enabled = config('probe.watchers', []);
        $storage = $this->app->make(StorageDriverInterface::class);

        foreach (self::WATCHER_MAP as $key => $watcherClass) {
            if (empty($enabled[$key])) {
                continue;
            }

            /** @var \Morcen\Probe\Watchers\Watcher $watcher */
            $watcher = new $watcherClass($storage);
            $watcher->register();

            $this->watchers[] = $watcher;
        }
    }

    private function registerRoutes(): void
    {
        $path = config('probe.path', 'probe');

        Route::group([
            'prefix'     => $path,
            'middleware' => [ProbeAuthentication::class],
        ], function () {
            // Dashboard SPA
            Route::get('/', [ProbeController::class, 'index'])->name('probe.index');

            Route::prefix('api')->group(function () {
                // Core data
                Route::get('/entries',       [ProbeController::class, 'entries'])->name('probe.entries');
                Route::get('/entries/{id}',  [ProbeController::class, 'show'])->name('probe.entry');
                Route::get('/stats',         [ProbeController::class, 'stats'])->name('probe.stats');

                // Real-time
                Route::get('/stream',        [ProbeController::class, 'stream'])->name('probe.stream');

                // Smart analysis (Phase 4)
                Route::get('/exceptions/groups', [ProbeController::class, 'exceptionGroups'])->name('probe.exceptions.groups');
                Route::get('/queries/slow',      [ProbeController::class, 'slowQueries'])->name('probe.queries.slow');
                Route::get('/queries/n1',        [ProbeController::class, 'n1Report'])->name('probe.queries.n1');

                // Export (Phase 5)
                Route::get('/export', [ProbeController::class, 'export'])->name('probe.export');
            });
        });
    }

    /**
     * Reset per-request watcher state when running under Laravel Octane.
     * Octane reuses the same process (and the same watcher instances)
     * across requests, so per-request maps (e.g. QueryWatcher::$queryMap)
     * must be cleared between requests. This must NOT re-register the
     * watchers: calling bootWatchers() again here would attach a brand new
     * set of event listeners on top of the existing ones every request,
     * stacking duplicate listeners (and duplicate recorded entries)
     * without bound for the lifetime of the worker.
     */
    private function registerOctaneListeners(): void
    {
        if (! class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        app('events')->listen(\Laravel\Octane\Events\RequestReceived::class, function () {
            foreach ($this->watchers as $watcher) {
                $watcher->resetPerRequestState();
            }
        });
    }

    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/probe.php' => config_path('probe.php'),
        ], 'probe-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'probe-migrations');
    }
}
