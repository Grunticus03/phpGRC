<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Core Config
|--------------------------------------------------------------------------
| Centralized configuration keys for phpGRC. All settings are stored in DB
| as system of record. This file only defines defaults/placeholders.
| Phase 4 adds RBAC, Audit, Evidence, and Avatars keys.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Auth (Phase 2 scaffolds)
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | RBAC (Phase 4 scaffold)
    |--------------------------------------------------------------------------
    */
    'rbac' => [
        'enabled' => env('CORE_RBAC_ENABLED', true),
        'roles' => [
            'Admin',
            'Auditor',
            'Risk Manager',
            'User',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit (Phase 4 scaffold)
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => env('CORE_AUDIT_ENABLED', true),
        'retention_days' => env('CORE_AUDIT_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence (Phase 4 scaffold)
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Avatars (Phase 4 scaffold)
    |--------------------------------------------------------------------------
    */
    'avatars' => [
        'enabled' => env('CORE_AVATARS_ENABLED', true),
        'size_px' => env('CORE_AVATARS_SIZE_PX', 128),
        'format'  => env('CORE_AVATARS_FORMAT', 'webp'),
    ],

];
