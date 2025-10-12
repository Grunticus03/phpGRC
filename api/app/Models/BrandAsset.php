<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $kind
 * @property string $name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string $profile_id
 * @property string $bytes
 * @property int|null $uploaded_by
 * @property string|null $uploaded_by_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class BrandAsset extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'brand_assets';

    protected $fillable = [
        'id',
        'profile_id',
        'kind',
        'name',
        'mime',
        'size_bytes',
        'sha256',
        'bytes',
        'uploaded_by',
        'uploaded_by_name',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BrandProfile::class, 'profile_id');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            /** @var mixed $current */
            $current = $model->getAttribute('id');
            if (! is_string($current) || trim($current) === '') {
                $model->setAttribute('id', 'ba_'.(string) Str::ulid());
            }
        });
    }
}
