<?php

declare(strict_types=1);

/**
 * Psalm bootstrap to ensure expected Laravel environment values exist when the
 * Laravel plugin boots the application in trimmed-down CI contexts.
 */
$defaultEnv = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://localhost',
    'APP_KEY' => 'base64:4sC72kLZfrN7kYM/qfNwCuWHa3gwPrmDkSumCF1FpHc=',
    'CACHE_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
];

foreach ($defaultEnv as $key => $value) {
    if (! getenv($key)) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

$pluginCacheDir = getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

if ($pluginCacheDir === false || $pluginCacheDir === '') {
    $hash = substr(md5(__DIR__), 0, 12);
    $pluginCacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "psalm-plugin-{$hash}";
}

if (! is_dir($pluginCacheDir) && ! @mkdir($pluginCacheDir, 0775, true) && ! is_dir($pluginCacheDir)) {
    throw new RuntimeException("Unable to create Psalm plugin cache directory at {$pluginCacheDir}");
}

putenv("PSALM_LARAVEL_PLUGIN_CACHE_PATH={$pluginCacheDir}");
$_ENV['PSALM_LARAVEL_PLUGIN_CACHE_PATH'] = $pluginCacheDir;
$_SERVER['PSALM_LARAVEL_PLUGIN_CACHE_PATH'] = $pluginCacheDir;
