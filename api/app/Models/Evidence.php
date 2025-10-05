<?php
// api/app/Models/Evidence.php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $owner_id
 * @property string $filename
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int $version
 * @property string $bytes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class Evidence extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    /** @var array<int,string> */
    protected $fillable = [
        'id',
        'owner_id',
        'filename',
        'mime',
        'size_bytes',
        'sha256',
        'version',
        'bytes',
        'created_at',
        'updated_at',
    ];

    /**
     * @phpstan-var array<string,string>
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'owner_id'   => 'integer',
        'size_bytes' => 'integer',
        'version'    => 'integer',
        'bytes'      => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Ensure IDs are generated server-side and not user-controlled.
     */
    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            /** @var mixed $attr */
            $attr = $model->getAttribute('id');
            /** @var string|null $id */
            $id = is_string($attr) ? $attr : null;

            if ($id === null || $id === '') {
                $model->setAttribute('id', 'ev_' . (string) Str::ulid());
            }
        });
    }
}
