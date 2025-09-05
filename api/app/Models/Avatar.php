<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder model for user avatars.
 */
final class Avatar extends Model
{
    protected $table = 'avatars';
    protected $guarded = [];
    public $timestamps = true;
}
