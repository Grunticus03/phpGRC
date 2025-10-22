<?php

declare(strict_types=1);

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$defaultSamlEntityId = sprintf('%s/saml/sp', $appUrl);
$defaultSamlAcs = sprintf('%s/auth/saml/acs', $appUrl);
$defaultSamlMetadata = sprintf('%s/auth/saml/metadata', $appUrl);

return [
    // Persistence gates (informational; not used to block writes)
    'settings' => [
        'stub_only' => false,
    ],

    // Setup Wizard defaults
    'setup' => [
        'enabled' => true,
        'shared_config_path' => '/opt/phpgrc/shared/config.php',
        'allow_commands' => false,
    ],

    'auth' => [
        'local' => [
            'enabled' => true,
        ],
        'mfa' => [
            'totp' => [
                'required_for_admin' => true,
                'issuer' => 'phpGRC',
                'digits' => 6,
                'period' => 30,
                'algorithm' => 'SHA1',
            ],
        ],
        'break_glass' => [
            'enabled' => false,
        ],

        // Brute-force guard knobs
        'bruteforce' => [
            'enabled' => true,
            'strategy' => 'session', // 'session' | 'ip'
            'window_seconds' => 900,
            'max_attempts' => 5,
            'lock_http_status' => 429,
        ],

        // Cookie used by session strategy
        'session_cookie' => [
            'name' => 'phpgrc_auth_attempt',
        ],

        'token_cookie' => [
            'name' => 'phpgrc_token',
            'ttl_minutes' => 120,
            'same_site' => 'strict',
        ],

        'saml' => [
            'sp' => [
                'entity_id' => env('SAML_SP_ENTITY_ID', $defaultSamlEntityId),
                'acs_url' => env('SAML_SP_ACS_URL', $defaultSamlAcs),
                'metadata_url' => env('SAML_SP_METADATA_URL', $defaultSamlMetadata),
                'sign_authn_requests' => env('SAML_SP_SIGN_REQUESTS', false),
                'want_assertions_signed' => env('SAML_SP_WANT_ASSERTIONS_SIGNED', true),
            ],
        ],
    ],

    'rbac' => [
        'enabled' => true,
        // Use DB to control persistence/require_auth; defaults below are safe for tests
        'mode' => 'stub',
        'persistence' => true,

        // Default false; set true via DB override: core.rbac.require_auth
        'require_auth' => false,

        // Default role names; storage tokens normalized elsewhere
        'roles' => [
            'Admin',
            'Auditor',
            'Risk Manager',
            'Theme Manager',
            'Theme Auditor',
            'User',
        ],

        // User search defaults
        'user_search' => [
            'default_per_page' => 50,
        ],

        // PolicyMap defaults; override via DB if needed
        'policies' => [
            'core.settings.manage' => ['role_admin'],
            'core.audit.view' => ['role_admin', 'role_auditor', 'role_risk_manager'],
            'core.audit.export' => ['role_admin', 'role_auditor'],
            'core.metrics.view' => ['role_admin', 'role_auditor', 'role_risk_manager'],
            'core.reports.view' => ['role_admin', 'role_auditor', 'role_risk_manager'],
            'core.users.view' => ['role_admin'],
            'core.users.manage' => ['role_admin'],
            'core.evidence.view' => ['role_admin', 'role_auditor', 'role_risk_manager', 'role_user'],
            'core.evidence.manage' => ['role_admin', 'role_risk_manager'],
            'core.exports.generate' => ['role_admin', 'role_risk_manager'],
            'core.rbac.view' => ['role_admin', 'role_auditor'],
            'rbac.roles.manage' => ['role_admin'],
            'rbac.user_roles.manage' => ['role_admin'],
            'ui.theme.view' => ['role_admin', 'role_auditor', 'role_theme_manager', 'role_theme_auditor'],
            'ui.theme.manage' => ['role_admin', 'role_theme_manager'],
            'ui.theme.pack.manage' => ['role_admin', 'role_theme_manager'],
            'integrations.connectors.manage' => ['role_admin'],
            'auth.idp.manage' => ['role_admin'],
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
                'delete' => true,
            ],
            'audit' => [
                'export' => true,
            ],
            'theme' => [
                'view' => true,
                'manage' => true,
                'pack' => [
                    'manage' => true,
                ],
            ],
        ],
    ],

    'audit' => [
        'enabled' => true,
        'retention_days' => 365,
        // CSV export iteration mode
        'csv_use_cursor' => true,
    ],

    // KPI defaults; DB overrides take precedence
    'metrics' => [
        'cache_ttl_seconds' => 0, // 0 disables caching
        'rbac_denies' => [
            'window_days' => 7,
        ],
        // Clamp bounds for window parameters and future range computations
        'window' => [
            'min_days' => 7,
            'max_days' => 365,
        ],
        // Deprecated: replaced by GenericRateLimit per-route in Phase 5
        'throttle' => [
            'enabled' => false,
            'per_minute' => 30,
            'window_seconds' => 60,
        ],
    ],

    // General API throttle (reusable) — defaults may be overridden by DB core_settings
    'api' => [
        'throttle' => [
            'enabled' => env('CORE_API_THROTTLE_ENABLED', false),
            'strategy' => env('CORE_API_THROTTLE_STRATEGY', 'ip'),   // ip|session|user
            'window_seconds' => (int) env('CORE_API_THROTTLE_WINDOW_SECONDS', 60),
            'max_requests' => (int) env('CORE_API_THROTTLE_MAX_REQUESTS', 30),
        ],
    ],

    'evidence' => [
        'enabled' => true,
        'max_mb' => 25,
        'allowed_mime' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'text/plain',
        ],
        'blob_storage_path' => '/opt/phpgrc/shared/blobs',
    ],

    'avatars' => [
        'enabled' => true,
        'size_px' => 128,
        'format' => 'webp',
    ],

    'ui' => [
        'time_format' => 'LOCAL',
    ],

    // Exports defaults
    'exports' => [
        'enabled' => true,
        'disk' => 'local',
        'dir' => 'exports',
    ],
];
