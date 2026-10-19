<?php

use Composer\InstalledVersions;

require __DIR__.'/../../vendor/autoload.php';

// Run in a separate Pest process: these namespaces must resolve to the released
// fixtures before any current core/client classes are loaded.
$fixtures = __DIR__.'/../Fixtures/v1';
spl_autoload_register(static function (string $class) use ($fixtures): void {
    foreach (['Tether\\Core\\' => 'core', 'Tether\\Client\\' => 'client'] as $prefix => $package) {
        if (str_starts_with($class, $prefix)) {
            $path = $fixtures.'/'.$package.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (! is_file($path)) {
                throw new RuntimeException('Missing released compatibility fixture: '.$class);
            }
            require $path;
            return;
        }
    }
}, true, true);

// The v1 HTTP client reads its version from Composer even though its source is
// supplied by a fixture rather than an installed dependency in this process.
$installed = InstalledVersions::getRawData();
$installed['versions']['tether/client'] = [
    'pretty_version' => 'v1.0.0',
    'version' => '1.0.0.0',
    'install_path' => $fixtures.'/client',
];
InstalledVersions::reload($installed);
