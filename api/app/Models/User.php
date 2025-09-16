<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
final class User extends Authenticatable
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;
    use Notifiable;

    /** @var array<int, string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return BelongsToMany
     * @phpstan-return BelongsToMany<\App\Models\Role, $this>
     * @psalm-return BelongsToMany<\App\Models\Role>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function hasRole(string $roleName): bool
    {
        foreach ($this->roles as $role) {
            if (strcasecmp($role->name, $roleName) === 0) {
                return true;
            }
        }
        return $this->roles()->whereRaw('LOWER(name) = LOWER(?)', [$roleName])->exists();
    }

    /**
     * @param  array<int,string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $r) {
            if ($this->hasRole((string) $r)) {
                return true;
            }
        }
        return false;
    }
}

