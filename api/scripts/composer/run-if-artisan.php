#!/usr/bin/env php
<?php

declare(strict_types=1);

// Runs "php artisan <args>" only if ./artisan exists.

$root = dirname(__DIR__, 2); // /api
$artisan = $root.DIRECTORY_SEPARATOR.'artisan';

if (! is_file($artisan)) {
    fwrite(STDOUT, "artisan not found, skipping.\n");
    exit(0);
}

$args = array_slice($argv, 1);
$cmd = 'php '.escapeshellarg($artisan).' '.implode(' ', array_map('escapeshellarg', $args));
passthru($cmd, $code);
exit($code);
