<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\AuditEventBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Eloquent model for audit_events.
 *
 * @property string                   $id
 * @property \Carbon\CarbonImmutable  $occurred_at
 * @property int|null                 $actor_id
 * @property string                   $action
 * @property string                   $category
 * @property string                   $entity_type
 * @property string                   $entity_id
 * @property string|null              $ip
 * @property string|null              $ua
 * @property array<string,mixed>|null $meta
 * @property \Carbon\CarbonImmutable  $created_at
 *
 * @method static AuditEventBuilder query()
 * @method static AuditEventBuilder newModelQuery()
 * @method static AuditEventBuilder newQuery()
 */
final class AuditEvent extends Model
{
    public $incrementing = false;

    protected $table = 'audit_events';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'occurred_at',
        'actor_id',
        'action',
        'category',
        'entity_type',
        'entity_id',
        'ip',
        'ua',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'created_at'  => 'immutable_datetime',
        'meta'        => 'array',
    ];

    public $timestamps = false;

    /**
     * Use a custom builder to normalize `meta` on bulk inserts.
     */
    #[\Override]
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        if (!$query instanceof QueryBuilder) {
            throw new \InvalidArgumentException('Expected Illuminate\Database\Query\Builder');
        }

        return new AuditEventBuilder($query);
    }
}

