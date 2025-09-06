<?php

declare(strict_types=1);

namespace App\Support\Config;

final class ConfigDefaults
{
    public const RBAC_ENABLED = true;
    /** @var array<int,string> */
    public const ROLES = ['Admin', 'Auditor', 'Risk Manager', 'User'];

    public const AUDIT_ENABLED = true;
    public const AUDIT_RETENTION_DAYS = 365; // min 1, max 730

    public const EVIDENCE_ENABLED = true;
    public const EVIDENCE_MAX_MB = 25;
    /** @var array<int,string> */
    public const EVIDENCE_ALLOWED_MIME = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'text/plain',
    ];

    public const AVATARS_ENABLED = true;
    public const AVATARS_SIZE_PX = 128;
    public const AVATARS_FORMAT = 'image/webp';

    /** @return array<string,mixed> */
    public static function asArray(): array
    {
        return [
            'rbac' => [
                'enabled' => self::RBAC_ENABLED,
                'roles'   => self::ROLES,
            ],
            'audit' => [
                'enabled'        => self::AUDIT_ENABLED,
                'retention_days' => self::AUDIT_RETENTION_DAYS,
            ],
            'evidence' => [
                'enabled'      => self::EVIDENCE_ENABLED,
                'max_mb'       => self::EVIDENCE_MAX_MB,
                'allowed_mime' => self::EVIDENCE_ALLOWED_MIME,
            ],
            'avatars' => [
                'enabled'  => self::AVATARS_ENABLED,
                'size_px'  => self::AVATARS_SIZE_PX,
                'format'   => 'webp', // presentational string for clients
                '_mime'    => self::AVATARS_FORMAT, // internal MIME to validate uploads
            ],
        ];
    }
}
