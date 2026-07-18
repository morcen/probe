<?php

namespace Morcen\Probe\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Morcen\Probe\ProbeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            ProbeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Pin the suite to Testbench's in-memory sqlite connection. A
        // DB_CONNECTION exported in the developer's shell wins over
        // phpunit.xml <env> values ($_SERVER beats $_ENV in phpdotenv),
        // so the config must be set directly.
        $app['config']->set('database.default', 'testing');
    }
}
