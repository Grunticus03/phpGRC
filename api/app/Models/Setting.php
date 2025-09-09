<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Core settings overrides stored by dotted key (e.g., "core.audit.enabled").
 * Only deviations from defaults are persisted.
 */
final class Setting extends Model
{
    protected $table = 'core_settings';

    /** @var array<int, string> */
    protected $fillable = [
        'key',
        'value',
        'type',
        'updated_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'json',
    ];
}

