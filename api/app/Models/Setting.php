<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    // Store JSON-encoded text; decode on read.
    protected $casts = [
        'value' => 'array',
    ];
}
