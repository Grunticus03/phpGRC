<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $kind
 * @property bool $enabled
 * @property array<string,mixed> $config
 * @property array<string,mixed>|null $meta
 * @property \Carbon\CarbonImmutable|null $last_health_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class IntegrationConnector extends Model
{
    use HasUlids;

    protected $table = 'integration_connectors';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'name',
        'kind',
        'enabled',
        'config',
        'meta',
        'last_health_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => AsEncryptedArrayObject::class,
        'meta' => 'array',
        'last_health_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
