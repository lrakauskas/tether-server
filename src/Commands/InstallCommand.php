<?php

namespace Tether\Server\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'tether:install-server';

    protected $description = 'Publish Tether Server configuration and print next steps';

    public function handle(): int
    {
        $this->info('Installing Tether Server...');
        $this->newLine();

        $this->call('vendor:publish', [
            '--tag'    => 'tether-server-config',
            '--ansi'   => true,
        ]);

        $this->newLine();
        $this->info('Configuration published.');
        $this->newLine();

        $this->line('Next steps:');
        $this->line('  1. Run migrations:');
        $this->line('       php artisan migrate');
        $this->newLine();
        $this->line('  2. Register your syncable models in AppServiceProvider::boot():');
        $this->line('       use Tether\Server\SyncRegistry;');
        $this->line('       app(SyncRegistry::class)->register(\App\Models\Post::class);');
        $this->newLine();
        $this->line('     Or add the Syncable trait to each model:');
        $this->line('       use Tether\Server\Traits\Syncable;');
        $this->newLine();
        $this->line('  3. Configure route middleware in config/tether-server.php');
        $this->line('     (default: open - protect with auth middleware in production).');
        $this->newLine();

        $this->info('Done!');

        return self::SUCCESS;
    }
}
