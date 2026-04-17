# Probe for Laravel

A debugging and observability package for Laravel applications. Probe records requests, exceptions, queries, jobs, cache operations, and scheduled tasks — then surfaces them in a real-time dashboard.

![Probe Dashboard](docs/images/dashboard-placeholder.png)
*Dashboard screenshot — coming soon*

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

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

Supported channels: `slack`, `webhook`, `log`.

## Pruning

Schedule the prune command to keep your database clean:

```php
// routes/console.php
Schedule::command('probe:prune')->daily();
```

Pruning TTLs are configurable per entry type:

```env
PROBE_PRUNE_REQUESTS=7
PROBE_PRUNE_EXCEPTIONS=30
PROBE_PRUNE_JOBS=7
PROBE_PRUNE_QUERIES=3
PROBE_PRUNE_CACHE=1
PROBE_PRUNE_SCHEDULE=7
```

To clear all entries immediately:

```bash
php artisan probe:clear
```

## Sampling

For high-traffic production environments, record a fraction of entries:

```env
PROBE_SAMPLING_RATE=0.1  # record 10% of entries
```

## Laravel Octane

Probe supports Laravel Octane. Per-request state resets automatically between requests.

## Configuration Reference

Publish and review `config/probe.php` for all options. Key environment variables:

| Variable              | Default    | Description                          |
|-----------------------|------------|--------------------------------------|
| `PROBE_ENABLED`       | `true`     | Enable or disable Probe entirely     |
| `PROBE_PATH`          | `probe`    | Dashboard URI path                   |
| `PROBE_STORAGE_DRIVER`| `database` | Storage backend                      |
| `PROBE_SAMPLING_RATE` | `1.0`      | Fraction of entries to record        |

---

## Contributing

Contributions are welcome. Please follow these steps:

1. Fork the repository.
2. Create a branch: `git checkout -b feature/your-feature`.
3. Write tests for your changes.
4. Run the test suite: `./vendor/bin/pest`.
5. Open a pull request against `main`.

Please keep pull requests focused. One feature or fix per PR. Open an issue first for large changes so we can align on direction before you invest time writing code.

### Running Tests

```bash
composer install
./vendor/bin/pest
```

---

## License

Probe is open-sourced software licensed under the [MIT license](LICENSE).
