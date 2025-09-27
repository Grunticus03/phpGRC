<?php

declare(strict_types=1);

return [
    // Persistence gates (informational; not used to block writes)
    'settings' => [
        'stub_only' => false,
    ],

    // Setup Wizard defaults
    'setup' => [
        'enabled'            => true,
        'shared_config_path' => '/opt/phpgrc/shared/config.php',
        'allow_commands'     => false,
    ],

    'auth' => [
        'local' => [
            'enabled' => true,
        ],
        'mfa' => [
            'totp' => [
                'required_for_admin' => true,
                'issuer'    => 'phpGRC',
                'digits'    => 6,
                'period'    => 30,
                'algorithm' => 'SHA1',
            ],
        ],
        'break_glass' => [
            'enabled' => false,
        ],

        // Brute-force guard knobs
        'bruteforce' => [
            'enabled'         => true,
            'strategy'        => 'session', // 'session' | 'ip'
            'window_seconds'  => 900,
            'max_attempts'    => 5,
            'lock_http_status'=> 429,
        ],

        // Cookie used by session strategy
        'session_cookie' => [
            'name' => 'phpgrc_auth_attempt',
        ],
    ],

    'rbac' => [
        'enabled'      => true,
        // Use DB to control persistence/require_auth; defaults below are safe for tests
        'mode'         => 'stub',
        'persistence'  => false,

        // Default false; set true via DB override: core.rbac.require_auth
        'require_auth' => false,

        // Default role names; storage tokens normalized elsewhere
        'roles' => [
            'Admin',
            'Auditor',
            'Risk Manager',
            'User',
        ],

        // PolicyMap defaults; override via DB if needed
        'policies' => [
            'core.settings.manage'   => ['Admin'],
            'core.audit.view'        => ['Admin', 'Auditor'],
            'core.audit.export'      => ['Admin'],
            'core.metrics.view'      => ['Admin'],
            'core.users.view'        => ['Admin'],
            'core.users.manage'      => ['Admin'],
            'core.evidence.view'     => ['Admin', 'Auditor'],
            'core.evidence.manage'   => ['Admin'],
            'core.exports.generate'  => ['Admin'],
            'rbac.roles.manage'      => ['Admin'],
            'rbac.user_roles.manage' => ['Admin'],
        ],
    ],

    // Capability flags
    'capabilities' => [
        'core' => [
            'exports' => [
                'generate' => true,
            ],
            'evidence' => [
                'upload' => true,
            ],
            'audit' => [
                'export' => true,
            ],
        ],
    ],

    'audit' => [
        'enabled'        => true,
        'retention_days' => 365,
        // CSV export iteration mode
        'csv_use_cursor' => true,
    ],

    // KPI defaults; DB overrides take precedence
    'metrics' => [
        'cache_ttl_seconds' => 0, // 0 disables caching
        'evidence_freshness' => [
            'days' => 30,
        ],
        'rbac_denies' => [
            'window_days' => 7,
        ],
        // Lightweight API throttle for metrics endpoints
        'throttle' => [
            'enabled'        => true,
            'per_minute'     => 30,
            'window_seconds' => 60,
        ],
    ],

    'evidence' => [
        'enabled'      => true,
        'max_mb'       => 25,
        'allowed_mime' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'text/plain',
        ],
    ],

    'avatars' => [
        'enabled' => true,
        'size_px' => 128,
        'format'  => 'webp',
    ],

    // Exports defaults
    'exports' => [
        'enabled' => true,
        'disk'    => 'local',
        'dir'     => 'exports',
    ],
];
