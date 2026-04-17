<?php

namespace Morcen\Probe\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCommand extends Command
{
    protected $signature = 'probe:clear';

    protected $description = 'Truncate all Probe entries from storage';

    public function handle(): int
    {
        DB::table('probe_entries')->truncate();

        $this->info('Probe entries cleared.');

        return self::SUCCESS;
    }
}
