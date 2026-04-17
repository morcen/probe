<?php

namespace Morcen\Probe\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'probe:install';

    protected $description = 'Publish the Probe config and migration files';

    public function handle(): int
    {
        $this->publishConfig();
        $this->publishMigration();

        $this->info('Probe installed. Run `php artisan migrate` to create the entries table.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $destination = config_path('probe.php');

        if (File::exists($destination)) {
            $this->line('Config already exists — skipping.');
            return;
        }

        $this->callSilently('vendor:publish', [
            '--tag'      => 'probe-config',
            '--no-interaction' => true,
        ]);

        $this->info('Config published.');
    }

    private function publishMigration(): void
    {
        $migrationPath = database_path('migrations');

        $exists = collect(File::files($migrationPath))
            ->filter(fn ($file) => str_contains($file->getFilename(), 'create_probe_entries_table'))
            ->isNotEmpty();

        if ($exists) {
            $this->line('Migration already exists — skipping.');
            return;
        }

        $this->callSilently('vendor:publish', [
            '--tag'      => 'probe-migrations',
            '--no-interaction' => true,
        ]);

        $this->info('Migration published.');
    }
}
