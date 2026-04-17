<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Probe Enable
    |--------------------------------------------------------------------------
    | Set to false to disable all Probe recording and the dashboard entirely.
    */

    'enabled' => env('PROBE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Probe Path
    |--------------------------------------------------------------------------
    | The URI path where the Probe dashboard is accessible.
    */

    'path' => env('PROBE_PATH', 'probe'),

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    | Supported: "database"
    */

    'storage_driver' => env('PROBE_STORAGE_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Sampling Rate
    |--------------------------------------------------------------------------
    | A float between 0.0 and 1.0. 1.0 records every entry. 0.1 records 10%.
    | Useful for high-traffic production environments.
    */

    'sampling_rate' => (float) env('PROBE_SAMPLING_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Watchers
    |--------------------------------------------------------------------------
    | Toggle individual watchers on or off.
    */

    'watchers' => [
        'requests'   => (bool) env('PROBE_WATCHER_REQUESTS', true),
        'exceptions' => (bool) env('PROBE_WATCHER_EXCEPTIONS', true),
        'jobs'       => (bool) env('PROBE_WATCHER_JOBS', true),
        'queries'    => (bool) env('PROBE_WATCHER_QUERIES', true),
        'cache'      => (bool) env('PROBE_WATCHER_CACHE', false),
        'schedule'   => (bool) env('PROBE_WATCHER_SCHEDULE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning TTL (in days)
    |--------------------------------------------------------------------------
    | Entries older than the configured number of days are deleted by the
    | probe:prune command. Set null to never prune that type.
    */

    'pruning' => [
        'requests'   => (int) env('PROBE_PRUNE_REQUESTS', 7),
        'exceptions' => (int) env('PROBE_PRUNE_EXCEPTIONS', 30),
        'jobs'       => (int) env('PROBE_PRUNE_JOBS', 7),
        'queries'    => (int) env('PROBE_PRUNE_QUERIES', 3),
        'cache'      => (int) env('PROBE_PRUNE_CACHE', 1),
        'schedule'   => (int) env('PROBE_PRUNE_SCHEDULE', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watcher-Specific Configuration
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    | Fire notifications when entries match a rule. Each rule specifies:
    |   - types:   array of entry types to match (e.g. ['exceptions', 'jobs'])
    |   - tags:    array of tags — entry must contain at least one (e.g. ['failed', 'slow'])
    |   - channel: 'slack' | 'webhook' | 'log'
    |   - url:     Slack incoming webhook URL or custom endpoint (for slack/webhook channels)
    |
    | Example:
    |   ['types' => ['exceptions'], 'channel' => 'slack', 'url' => env('PROBE_SLACK_WEBHOOK')]
    */

    'alerts' => [
        // ['types' => ['exceptions'], 'channel' => 'slack', 'url' => env('PROBE_SLACK_WEBHOOK')],
        // ['types' => ['jobs'], 'tags' => ['failed'], 'channel' => 'webhook', 'url' => env('PROBE_WEBHOOK_URL')],
        // ['types' => ['queries'], 'tags' => ['slow'], 'channel' => 'log'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watcher-Specific Configuration
    |--------------------------------------------------------------------------
    */

    'watchers_config' => [

        'requests' => [
            // Paths to skip recording. Supports * wildcard. e.g. 'probe/*', 'health'
            'ignore_paths'        => [
                'probe/*',
                '_ignition/*',
                'telescope/*',
            ],
            // HTTP status codes to skip recording.
            'ignore_status_codes' => [],
        ],

        'queries' => [
            // Queries taking longer than this (ms) are tagged 'slow'.
            'slow_threshold' => (int) env('PROBE_SLOW_QUERY_MS', 100),
            // Same query fingerprint seen this many times in one request = tagged 'n1'.
            'n1_threshold'   => (int) env('PROBE_N1_THRESHOLD', 5),
        ],

        'exceptions' => [
            // Exception classes to never record.
            'ignore_exceptions'   => [
                // \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            ],
            // Strip vendor frames from stack traces.
            'strip_vendor_frames' => (bool) env('PROBE_STRIP_VENDOR_FRAMES', false),
        ],

    ],

];
