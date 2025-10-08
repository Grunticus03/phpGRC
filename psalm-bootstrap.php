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

