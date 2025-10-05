<?php

declare(strict_types=1);

// Drop stale cache files so package discovery always re-builds.
$cacheFiles = glob('bootstrap/cache/*.php');
if ($cacheFiles !== false) {
    foreach ($cacheFiles as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

$dirs = [
    'bootstrap/cache',
    'storage/app',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

foreach ($dirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

if (!file_exists('.env') && file_exists('.env.example')) {
    @copy('.env.example', '.env');
}
