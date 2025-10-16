<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string|null $theme
 * @property string|null $mode
 * @property string|null $overrides
 * @property bool $sidebar_collapsed
 * @property bool $sidebar_pinned
 * @property int $sidebar_width
 * @property string|null $sidebar_order
 * @property string|null $sidebar_hidden
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UserUiPreference extends Model
{
    protected $table = 'user_ui_prefs';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'theme',
        'mode',
        'overrides',
        'sidebar_collapsed',
        'sidebar_pinned',
        'sidebar_width',
        'sidebar_order',
        'sidebar_hidden',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'user_id' => 'integer',
        'sidebar_collapsed' => 'boolean',
        'sidebar_pinned' => 'boolean',
        'sidebar_width' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
