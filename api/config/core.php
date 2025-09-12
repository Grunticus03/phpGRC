<?php

declare(strict_types=1);

return [
    // Persistence gates
    'settings' => [
        'stub_only' => filter_var(env('CORE_SETTINGS_STUB_ONLY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
    ],

    // Setup Wizard (bugfix scope) :contentReference[oaicite:8]{index=8}
    'setup' => [
        'enabled'            => filter_var(env('CORE_SETUP_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'shared_config_path' => env('CORE_SETUP_SHARED_CONFIG_PATH', '/opt/phpgrc/shared/config.php'),
        'allow_commands'     => filter_var(env('CORE_SETUP_ALLOW_COMMANDS', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
    ],

    'auth' => [
        'local' => [
            'enabled' => env('CORE_AUTH_LOCAL_ENABLED', true),
        ],
        'mfa' => [
            'totp' => [
                'required_for_admin' => env('CORE_AUTH_MFA_TOTP_REQUIRED_FOR_ADMIN', true),
                'issuer'    => env('CORE_AUTH_MFA_TOTP_ISSUER', 'phpGRC'),
                'digits'    => env('CORE_AUTH_MFA_TOTP_DIGITS', 6),
                'period'    => env('CORE_AUTH_MFA_TOTP_PERIOD', 30),
                'algorithm' => env('CORE_AUTH_MFA_TOTP_ALGORITHM', 'SHA1'),
            ],
        ],
        'break_glass' => [
            'enabled' => env('CORE_AUTH_BREAK_GLASS_ENABLED', false),
        ],
    ],

    'rbac' => [
        'enabled'      => true,
        // Mode controls controllers/validation behavior. Default stub.
        // Values: 'stub' | 'persist'
        'mode'         => env('CORE_RBAC_MODE', 'stub'),
        // Back-compat boolean toggle; either this OR mode=persist enables persistence paths.
        'persistence'  => filter_var(env('CORE_RBAC_PERSISTENCE', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        'require_auth' => env('CORE_RBAC_REQUIRE_AUTH', false),
        'roles' => [
            'Admin',
            'Auditor',
            'Risk Manager',
            'User',
        ],
    ],

    // Capability flags
    'capabilities' => [
        'core' => [
            'exports' => [
                'generate' => env('CAP_CORE_EXPORTS_GENERATE', true),
            ],
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

    // Exports defaults
    'exports' => [
        'enabled' => env('CORE_EXPORTS_ENABLED', true),
        'disk'    => env('CORE_EXPORTS_DISK', env('FILESYSTEM_DISK', 'local')),
        'dir'     => env('CORE_EXPORTS_DIR', 'exports'),
    ],
];

