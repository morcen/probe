# Probe for Laravel

A debugging and observability package for Laravel applications. Probe records requests, exceptions, queries, jobs, cache operations, and scheduled tasks — then surfaces them in a real-time dashboard.

*Dashboard screenshot — coming soon*

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

> **Note:** Laravel 10 and 11 are end-of-life and carry known, unpatched upstream security advisories. Probe itself remains compatible with them, but running on an EOL Laravel version means your application inherits those open advisories regardless of anything Probe does. Upgrading to Laravel 12 is recommended where possible.

## Installation

```bash
composer require morcen/probe
```

Run the install command to publish the config and migrate:

```bash
php artisan probe:install
```

Or publish assets manually:

```bash
php artisan vendor:publish --tag=probe-config
php artisan vendor:publish --tag=probe-migrations
php artisan migrate
```

## Dashboard Access

Visit `/probe` in your browser. By default, access is restricted to local environments. To customize authorization, add the following to your `AppServiceProvider`:

```php
use Morcen\Probe\Probe;

Probe::auth(function ($request) {
    return $request->user()?->isAdmin();
});
```

You can change the dashboard path via the `PROBE_PATH` env variable or the config file.

## Watchers

Probe ships with six watchers. Toggle them in `config/probe.php` or via environment variables:

| Watcher    | Env Variable                    | Default |
|------------|---------------------------------|---------|
| Requests   | `PROBE_WATCHER_REQUESTS`        | `true`  |
| Exceptions | `PROBE_WATCHER_EXCEPTIONS`      | `true`  |
| Jobs       | `PROBE_WATCHER_JOBS`            | `true`  |
| Queries    | `PROBE_WATCHER_QUERIES`         | `true`  |
| Cache      | `PROBE_WATCHER_CACHE`           | `false` |
| Schedule   | `PROBE_WATCHER_SCHEDULE`        | `true`  |

### Query intelligence

The query watcher automatically tags slow queries and detects N+1 patterns:

```env
PROBE_SLOW_QUERY_MS=100   # queries over this threshold are tagged "slow"
PROBE_N1_THRESHOLD=5      # same query fingerprint N times = tagged "n1"
```

## Alerts

Probe fires notifications when entries match a rule. Configure rules in `config/probe.php`:

```php
'alerts' => [
    ['types' => ['exceptions'], 'channel' => 'slack', 'url' => env('PROBE_SLACK_WEBHOOK')],
    ['types' => ['jobs'], 'tags' => ['failed'], 'channel' => 'webhook', 'url' => env('PROBE_WEBHOOK_URL')],
    ['types' => ['queries'], 'tags' => ['slow'], 'channel' => 'log'],
],
```
