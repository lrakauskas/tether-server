<?php

use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

// Resolve real package metadata offline against the already installed Laravel
// dependency graph. No downloads, install operations, or project lock changes.
$root = dirname(__DIR__, 2);
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$server = $read($root.'/composer.json');
$core = $read(__DIR__.'/../Fixtures/v1/core/composer.json');
$installed = $read($root.'/vendor/composer/installed.json');
$packages = array_values(array_filter($installed['packages'] ?? $installed, static fn (array $package): bool => ! str_starts_with($package['name'], 'tether/')));
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$command = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];

foreach (['0.0.1', '1.0.0'] as $version) {
    $directory = sys_get_temp_dir().'/tether-solver-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $server['version'] = '1.0.1';
    $core['version'] = $version;
    $project = [
        'name' => 'tether/compatibility-check',
        'require' => ['tether/server' => '1.0.1', 'tether/core' => $version],
        'repositories' => [
            ['type' => 'package', 'package' => array_merge([$server, $core], $packages)],
            ['packagist.org' => false],
        ],
        'config' => ['allow-plugins' => false],
    ];

    try {
        file_put_contents($directory.'/composer.json', json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $process = new Process(array_merge($command, [
            'update', '--dry-run', '--no-install', '--no-scripts', '--no-plugins',
            '--no-audit', '--no-interaction', '--no-progress',
        ]), $directory, ['COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER' => 'composer.json']);
        $process->mustRun();
        echo "Resolved corrected tether/server with released tether/core {$version}.".PHP_EOL;
    } finally {
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (is_file($directory.'/'.$file)) {
                unlink($directory.'/'.$file);
            }
        }
        rmdir($directory);
    }
}
