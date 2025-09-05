<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder model for evidence records.
 */
final class Evidence extends Model
{
    protected $table = 'evidence';
    protected $guarded = [];
    public $timestamps = false;
}
