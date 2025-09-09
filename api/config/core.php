<?php

declare(strict_types=1);

return [
    // Persistence gate. Default ON so controller/validation tests expect stub-only responses.
    'settings' => [
        'stub_only' => filter_var(env('CORE_SETTINGS_STUB_ONLY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
    ],

    'auth' => [
        'local' => [
            'enabled' => env('CORE_AUTH_LOCAL_ENABLED', true),
        ],
        'mfa' => [
            'totp' => [
                'required_for_admin' => env('CORE_AUTH_MFA_TOTP_REQUIRED_FOR_ADMIN', true),
                'issuer'   => env('CORE_AUTH_MFA_TOTP_ISSUER', 'phpGRC'),
                'digits'   => env('CORE_AUTH_MFA_TOTP_DIGITS', 6),
                'period'   => env('CORE_AUTH_MFA_TOTP_PERIOD', 30),
                'algorithm'=> env('CORE_AUTH_MFA_TOTP_ALGORITHM', 'SHA1'),
            ],
        ],
        'break_glass' => [
            'enabled' => env('CORE_AUTH_BREAK_GLASS_ENABLED', false),
        ],
    ],

    'rbac' => [
        'enabled' => true,
        'require_auth' => env('CORE_RBAC_REQUIRE_AUTH', false),
        'roles' => [
            'Admin',
            'Auditor',
            'Risk Manager',
            'User',
        ],
    ],

    'audit' => [
        'enabled' => env('CORE_AUDIT_ENABLED', true),
        'retention_days' => env('CORE_AUDIT_RETENTION_DAYS', 365),
    ],

    'evidence' => [
        'enabled' => env('CORE_EVIDENCE_ENABLED', true),
        'max_mb' => env('CORE_EVIDENCE_MAX_MB', 25),
        'allowed_mime' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'text/plain',
        ],
    ],

    'avatars' => [
        'enabled' => env('CORE_AVATARS_ENABLED', true),
        'size_px' => env('CORE_AVATARS_SIZE_PX', 128),
        'format'  => env('CORE_AVATARS_FORMAT', 'webp'),
    ],
];
