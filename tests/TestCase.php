<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Tether\Core\TetherCoreServiceProvider;
use Tether\Server\TetherServerServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            TetherCoreServiceProvider::class,
            TetherServerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tether-server.model_namespace', 'Tests\\Models');
        $app['config']->set('tether-server.sync_key', 'tether_id');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            __DIR__ . '/../database/migrations',
            __DIR__ . '/database/migrations',
        ]);
    }
}
