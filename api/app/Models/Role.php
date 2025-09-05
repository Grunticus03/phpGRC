<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Placeholder model for RBAC roles.
 * No relations or business logic in Phase 4.
 */
final class Role extends Model
{
    protected $table = 'roles';
    protected $guarded = [];
    public $timestamps = true;
}
