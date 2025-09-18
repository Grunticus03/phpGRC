<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

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
    /** @var bool */
    public $incrementing = false;

    /** @var string */
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
     * @psalm-suppress NonInvariantDocblockPropertyType
     * @psalm-var array<array-key,mixed>
     * @phpstan-var array<string,string>
     */
    protected $casts = [
        'owner_id'   => 'integer',
        'size_bytes' => 'integer',
        'version'    => 'integer',
        'bytes'      => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

