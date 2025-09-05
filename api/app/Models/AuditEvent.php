<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder Eloquent model for audit_events.
 * Phase 4: shape only; no persistence or relations.
 *
 * @property string      $id
 * @property string      $occurred_at
 * @property int|null    $actor_id
 * @property string      $action
 * @property string      $entity_type
 * @property string      $entity_id
 * @property string|null $ip
 * @property string|null $ua
 * @property array|null  $meta
 */
final class AuditEvent extends Model
{
    public $incrementing = false;
    protected $table = 'audit_events';
    protected $keyType = 'string';
    protected $fillable = [
        'id', 'occurred_at', 'actor_id', 'action', 'entity_type', 'entity_id', 'ip', 'ua', 'meta',
    ];
    protected $casts = [
        'meta' => 'array',
    ];
    // Timestamps not required for this stub.
    public $timestamps = false;
}
