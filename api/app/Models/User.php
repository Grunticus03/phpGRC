<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Placeholder User model for Phase 2 skeleton.
 * - Fields will be reconciled when CORE-004 (RBAC roles) introduces full user model.
 * - Only minimal fillable/hidden fields defined to keep CI green.
 *
 * @use HasApiTokens<\Laravel\Sanctum\PersonalAccessToken>
 */
final class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var array<int, string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];
}
