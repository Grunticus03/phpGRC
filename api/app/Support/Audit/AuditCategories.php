<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class AuditCategories
{
    public const SYSTEM   = 'SYSTEM';
    public const RBAC     = 'RBAC';
    public const AUTH     = 'AUTH';
    public const SETTINGS = 'SETTINGS';
    public const EXPORTS  = 'EXPORTS';
    public const EVIDENCE = 'EVIDENCE';
    public const AVATARS  = 'AVATARS';
    public const AUDIT    = 'AUDIT';

    /** @var array<int,string> */
    public const ALL = [
        self::SYSTEM,
        self::RBAC,
        self::AUTH,
        self::SETTINGS,
        self::EXPORTS,
        self::EVIDENCE,
        self::AVATARS,
        self::AUDIT,
    ];
}

