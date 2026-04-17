<?php

namespace Morcen\Probe\Console;

use Illuminate\Console\Command;
use Morcen\Probe\Storage\StorageDriverInterface;

class PruneCommand extends Command
{
    protected $signature = 'probe:prune';

    protected $description = 'Delete Probe entries older than the configured TTL per entry type';

    public function handle(StorageDriverInterface $driver): int
    {
        $driver->prune();

        $this->info('Probe entries pruned successfully.');

        return self::SUCCESS;
    }
}
