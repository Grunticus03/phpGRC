<?php

declare(strict_types=1);

/**
 * Core feature flags and defaults (Phase 2 placeholders).
 * These mirror future DB-backed settings. No enforcement yet.
 *
 * Keys map to: config('core.auth.*')
 */
return [
    'auth' => [
        'local' => [
            // Local username/password auth feature flag
            'enabled' => env('CORE_AUTH_LOCAL_ENABLED', true),
        ],

        'mfa' => [
            'totp' => [
                // Require MFA for admin accounts once enabled in later tasks
                'required_for_admin' => env('CORE_AUTH_MFA_TOTP_REQUIRED_FOR_ADMIN', true),

                // TOTP defaults (placeholders; enforcement added later)
                'issuer'    => env('CORE_AUTH_MFA_TOTP_ISSUER', 'phpGRC'),
                'digits'    => 6,
                'period'    => 30,
                'algorithm' => 'SHA1', // RFC 6238 default; consider SHA256 later
            ],
        ],

        'break_glass' => [
            // Emergency login is OFF by default; wired later with DB flag + audit
            'enabled' => env('CORE_AUTH_BREAK_GLASS_ENABLED', false),
        ],
    ],
];
