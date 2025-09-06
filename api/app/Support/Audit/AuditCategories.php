<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class AuditCategories
{
    /** @var array<int,string> */
    public const ALL = [
        'SETTINGS',
        'AUTH',
        'RBAC',
        'EVIDENCE',
        'EXPORTS',
        'AVATAR',
    ];
}
