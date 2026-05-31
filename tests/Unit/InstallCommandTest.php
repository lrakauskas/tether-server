<?php

use function Pest\Laravel\artisan;

it('publishes server config and prints setup steps', function () {
    artisan('tether:install-server')
        ->expectsOutputToContain('Installing Tether Server')
        ->expectsOutputToContain('Configuration published')
        ->expectsOutputToContain('SyncRegistry')
        ->expectsOutputToContain('Syncable')
        ->assertSuccessful();
});
