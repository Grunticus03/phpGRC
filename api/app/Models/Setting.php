<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value JSON string payload
 * @property string $type
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Setting extends Model
{
    protected $table = 'core_settings';

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
     * Store JSON as text; service decodes/encodes explicitly.
     *
     * @psalm-var array<array-key,mixed>
     *
     * @phpstan-var array<string,string>
     */
    protected $casts = [
        'value' => 'string',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
