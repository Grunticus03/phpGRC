<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $slug
 * @property string $name
 * @property string|null $version
 * @property string|null $author
 * @property string|null $license_name
 * @property string|null $license_file
 * @property bool $enabled
 * @property int|null $imported_by
 * @property string|null $imported_by_name
 * @property array<string,string>|null $assets
 * @property array<int,array<string,mixed>>|null $files
 * @property array<string,array<int,string>>|null $inactive
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UiThemePack extends Model
{
    protected $table = 'ui_theme_packs';

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'slug',
        'name',
        'version',
        'author',
        'license_name',
        'license_file',
        'enabled',
        'imported_by',
        'imported_by_name',
        'assets',
        'files',
        'inactive',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'enabled' => 'boolean',
        'imported_by' => 'integer',
        'assets' => 'array',
        'files' => 'array',
        'inactive' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @phpstan-return \Illuminate\Database\Eloquent\Relations\HasMany<UiThemePackFile, self>
     *
     * @psalm-return \Illuminate\Database\Eloquent\Relations\HasMany<UiThemePackFile>
     */
    public function entries(): HasMany
    {
        /** @phpstan-ignore-next-line */
        return $this->hasMany(UiThemePackFile::class, 'pack_slug', 'slug');
    }
}
