<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder Avatar model (Phase 4).
 * Processing and storage deferred.
 */
final class Avatar extends Model
{
    protected $table = 'avatars';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'path', 'mime', 'size_bytes', 'width', 'height',
    ];
}
