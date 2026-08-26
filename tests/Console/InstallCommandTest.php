<?php

use Illuminate\Support\Facades\File;
use Morcen\Probe\ProbeServiceProvider;

beforeEach(function () {
    // Publish into a throwaway directory rather than the real Testbench
    // skeleton, so the test neither pollutes vendor/ nor depends on
    // whatever a previous test run may have already published there.
    $this->installTempPath = sys_get_temp_dir() . '/probe-install-test-' . uniqid();

    File::ensureDirectoryExists($this->installTempPath . '/config');
    File::ensureDirectoryExists($this->installTempPath . '/database/migrations');

    app()->useConfigPath($this->installTempPath . '/config');
    app()->useDatabasePath($this->installTempPath . '/database');

    // ProbeServiceProvider registered its publishes() destinations against
    // the real config/database paths during the app's initial boot, before
    // the paths above were overridden — re-run just that registration so
    // `probe:install` publishes into the throwaway directory instead.
    $registerPublishables = new ReflectionMethod(ProbeServiceProvider::class, 'registerPublishables');
    $registerPublishables->setAccessible(true);
    $registerPublishables->invoke(app()->getProvider(ProbeServiceProvider::class));
});

afterEach(function () {
    File::deleteDirectory($this->installTempPath);
});

it('publishes the config and migration on a fresh install', function () {
    $this->artisan('probe:install')
        ->expectsOutput('Config published.')
        ->expectsOutput('Migration published.')
        ->expectsOutput('Probe installed. Run `php artisan migrate` to create the entries table.')
        ->assertSuccessful();

    expect(File::exists(config_path('probe.php')))->toBeTrue();

    $migrations = collect(File::files(database_path('migrations')))
        ->filter(fn ($file) => str_contains($file->getFilename(), 'create_probe_entries_table'));

    expect($migrations)->not->toBeEmpty();
});

it('skips republishing the config and migration when they already exist', function () {
    $this->artisan('probe:install')->assertSuccessful();

    $this->artisan('probe:install')
        ->expectsOutput('Config already exists — skipping.')
        ->expectsOutput('Migration already exists — skipping.')
        ->assertSuccessful();
});
