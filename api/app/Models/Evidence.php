<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder Evidence model (Phase 4).
 * Storage and hashing deferred.
 */
final class Evidence extends Model
{
    protected $table = 'evidence';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'owner_id', 'filename', 'mime', 'size_bytes', 'sha256', 'created_at',
    ];

    public $timestamps = false;
}
