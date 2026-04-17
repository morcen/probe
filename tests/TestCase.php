<?php

namespace Morcen\Probe\Tests;

use Morcen\Probe\ProbeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ProbeServiceProvider::class,
        ];
    }
}
