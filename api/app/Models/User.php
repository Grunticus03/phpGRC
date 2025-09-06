<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Placeholder User model for Phase 2 skeleton.
 * - Fields reconciled in CORE-004.
 *
 * @psalm-use \Laravel\Sanctum\HasApiTokens<\Laravel\Sanctum\PersonalAccessToken>
 * @use \Laravel\Sanctum\HasApiTokens<\Laravel\Sanctum\PersonalAccessToken>
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
