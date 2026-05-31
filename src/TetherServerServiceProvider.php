<?php

namespace Tether\Server;

use Tether\Core\Sync\MutationApplicator;
use Tether\Server\Commands\InstallCommand;
use Tether\Server\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TetherServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/tether-server.php',
            'tether-server',
        );

        $this->app->singleton(MutationApplicator::class, function () {
            return new MutationApplicator(
                modelNamespace: config('tether-server.model_namespace', 'App\\Models'),
                syncKeyColumn: config('tether-server.sync_key', 'tether_id'),
            );
        });

        $this->app->singleton(SyncRegistry::class);

        $this->app->singleton(ConflictDetector::class, function ($app) {
            return new ConflictDetector(
                registry: $app->make(SyncRegistry::class),
            );
        });

        $this->app->singleton(StateSnapshotGenerator::class, function ($app) {
            return new StateSnapshotGenerator(
                registry: $app->make(SyncRegistry::class),
            );
        });

        $this->app->singleton(PushSyncProcessor::class, function ($app) {
            return new PushSyncProcessor(
                applicator: $app->make(MutationApplicator::class),
                registry: $app->make(SyncRegistry::class),
                conflictDetector: $app->make(ConflictDetector::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tether-server.php' => config_path('tether-server.php'),
            ], 'tether-server-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'tether-server-migrations');

            $this->commands([
                InstallCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if (config('tether-server.register_routes', true)) {
            Route::middleware(config('tether-server.middleware', []))
                ->prefix(config('tether-server.route_prefix', 'tether'))
                ->group(function () {
                    Route::post('/push', [SyncController::class, 'push'])->name('tether.push');
                    Route::post('/pull', [SyncController::class, 'pull'])->name('tether.pull');
                });
        }
    }
}
