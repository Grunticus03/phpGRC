<?php

return [
    // Placeholder config; enable SPA mode in later phase
    'stateful' => [],
    'expiration' => null,
    'middleware' => [
        'verify_csrf_token' => \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies' => \Illuminate\Cookie\Middleware\EncryptCookies::class,
    ],
];
