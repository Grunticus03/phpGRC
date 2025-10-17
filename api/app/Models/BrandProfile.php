<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $is_locked
 * @property string $title_text
 * @property string|null $favicon_asset_id
 * @property string|null $primary_logo_asset_id
 * @property string|null $secondary_logo_asset_id
 * @property string|null $header_logo_asset_id
 * @property string|null $footer_logo_asset_id
 * @property string|null $background_login_asset_id
 * @property string|null $background_main_asset_id
 * @property bool $footer_logo_disabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class BrandProfile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'brand_profiles';

    protected $fillable = [
        'id',
        'name',
        'is_default',
        'is_active',
        'is_locked',
        'title_text',
        'favicon_asset_id',
        'primary_logo_asset_id',
        'secondary_logo_asset_id',
        'header_logo_asset_id',
        'footer_logo_asset_id',
        'background_login_asset_id',
        'background_main_asset_id',
        'footer_logo_disabled',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'footer_logo_disabled' => 'boolean',
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            /** @var mixed $current */
            $current = $model->getAttribute('id');
            if (! is_string($current) || trim($current) === '') {
                $model->setAttribute('id', 'bp_'.(string) Str::ulid());
            }
        });
    }
}
