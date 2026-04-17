<?php

use Morcen\Probe\Storage\DatabaseDriver;
use Morcen\Probe\Storage\StorageDriverInterface;

it('loads the probe config', function () {
    expect(config('probe'))->toBeArray()
        ->and(config('probe.enabled'))->toBeBool()
        ->and(config('probe.path'))->toBe('probe')
        ->and(config('probe.storage_driver'))->toBe('database')
        ->and(config('probe.sampling_rate'))->toBe(1.0)
        ->and(config('probe.watchers'))->toBeArray()
        ->and(config('probe.pruning'))->toBeArray();
});

it('registers the storage driver in the container', function () {
    $driver = app(StorageDriverInterface::class);

    expect($driver)->toBeInstanceOf(DatabaseDriver::class);
});

it('uses correct pruning defaults', function () {
    expect(config('probe.pruning.requests'))->toBe(7)
        ->and(config('probe.pruning.exceptions'))->toBe(30)
        ->and(config('probe.pruning.jobs'))->toBe(7)
        ->and(config('probe.pruning.queries'))->toBe(3);
});

it('disables recording when probe is turned off', function () {
    config()->set('probe.enabled', false);

    expect(config('probe.enabled'))->toBeFalse();
});
