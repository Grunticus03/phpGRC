<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidence model: stores file bytes, hash, and simple per-filename version.
 */
final class Evidence extends Model
{
    protected $table = 'evidence';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'owner_id',
        'filename',
        'mime',
        'size_bytes',
        'sha256',
        'version',
        'bytes',
        'created_at',
    ];

    protected $casts = [
        'owner_id'   => 'integer',
        'size_bytes' => 'integer',
        'version'    => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    // Avoid accidental exposure of raw bytes in generic toArray().
    protected $hidden = ['bytes'];
}
