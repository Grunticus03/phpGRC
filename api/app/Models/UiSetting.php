<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 * @property string $type
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UiSetting extends Model
{
    protected $table = 'ui_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'key',
        'value',
        'type',
        'updated_by',
    ];

    /**
     * @phpstan-var array<string,string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    protected $casts = [
        'value' => 'string',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
