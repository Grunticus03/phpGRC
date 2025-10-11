<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $pack_slug
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string $bytes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UiThemePackFile extends Model
{
    protected $table = 'ui_theme_pack_files';

    protected $fillable = [
        'pack_slug',
        'path',
        'mime',
        'size_bytes',
        'sha256',
        'bytes',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @phpstan-return \Illuminate\Database\Eloquent\Relations\BelongsTo<UiThemePack, self>
     *
     * @psalm-return \Illuminate\Database\Eloquent\Relations\BelongsTo<UiThemePack>
     */
    public function pack(): BelongsTo
    {
        /** @phpstan-ignore-next-line */
        return $this->belongsTo(UiThemePack::class, 'pack_slug', 'slug');
    }
}
