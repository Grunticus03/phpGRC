<?php

declare(strict_types=1);

/**
 * Phase 2 — Sanctum SPA scaffold (kept inert).
 * - Leave 'stateful' empty until SPA binds.
 * - Keep 'guard' commented to avoid enforcing anything.
 * - Middleware listed for future CSRF/cookie flow.
 */
return [
    'stateful' => [
        // e.g. 'localhost', 'phpgrc.test', 'app.example.com'
    ],

    // Minutes; null = do not expire
    'expiration' => null,

    // Sanctum will use these guards for stateful SPA requests (uncomment when enabling)
    // 'guard' => ['web'],

    'middleware' => [
        'verify_csrf_token' => Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies'   => Illuminate\Cookie\Middleware\EncryptCookies::class,
    ],
];
