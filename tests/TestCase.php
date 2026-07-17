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
}
