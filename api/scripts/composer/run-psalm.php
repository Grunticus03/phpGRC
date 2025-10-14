<?php

declare(strict_types=1);

$apiDir = dirname(__DIR__, 2);

if (! @chdir($apiDir)) {
    fwrite(STDERR, "Unable to change working directory to {$apiDir}\n");
    exit(1);
}

$repoRoot = dirname($apiDir);
$bootstrap = $repoRoot . DIRECTORY_SEPARATOR . 'psalm-bootstrap.php';

if (is_file($bootstrap)) {
    require_once $bootstrap;
} else {
    $pluginCacheDir = $apiDir . '/build/psalm-plugin-cache';
    if (! is_dir($pluginCacheDir) && ! @mkdir($pluginCacheDir, 0775, true) && ! is_dir($pluginCacheDir)) {
        fwrite(STDERR, "Unable to create Psalm plugin cache directory at {$pluginCacheDir}\n");
        exit(1);
    }

    putenv("PSALM_LARAVEL_PLUGIN_CACHE_PATH={$pluginCacheDir}");
    $_ENV['PSALM_LARAVEL_PLUGIN_CACHE_PATH'] = $pluginCacheDir;
    $_SERVER['PSALM_LARAVEL_PLUGIN_CACHE_PATH'] = $pluginCacheDir;
}

$psalmBinary = $apiDir . '/vendor/bin/psalm';

if (! is_file($psalmBinary)) {
    fwrite(STDERR, "Psalm binary not found at {$psalmBinary}\n");
    exit(1);
}

$args = array_merge(['--no-cache'], array_slice($_SERVER['argv'] ?? [], 1));

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($psalmBinary);

foreach ($args as $arg) {
    $command .= ' ' . escapeshellarg($arg);
}

passthru($command, $exitCode);
exit($exitCode);
