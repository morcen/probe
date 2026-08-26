<?php

use Morcen\Probe\Storage\StorageDriverInterface;

it('delegates pruning to the configured storage driver', function () {
    $driver = Mockery::mock(StorageDriverInterface::class);
    $driver->shouldReceive('prune')->once();

    app()->instance(StorageDriverInterface::class, $driver);

    $this->artisan('probe:prune')
        ->expectsOutput('Probe entries pruned successfully.')
        ->assertSuccessful();
});
