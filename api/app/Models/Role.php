<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder Role model (Phase 4).
 * No relations or business logic this phase.
 */
final class Role extends Model
{
    protected $table = 'roles';
    protected $fillable = ['name'];
}
