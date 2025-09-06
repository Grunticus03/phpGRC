<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

final class User extends Authenticatable
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    use Notifiable;

    /** @var array<int, string> */
    protected $fillable = ['name','email','password'];

    /** @var array<int, string> */
    protected $hidden = ['password','remember_token'];
}
