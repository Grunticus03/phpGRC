<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property-read EloquentCollection<int,\App\Models\Role> $roles
 */
final class User extends Authenticatable
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;
    use Notifiable;

    /** @var array<int, string> */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @phpstan-var array<int, string>
     * @psalm-var array<array-key, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Relation: user ↔ roles.
     *
     * @return BelongsToMany
     * @phpstan-return BelongsToMany<\App\Models\Role,\App\Models\User>
     * @psalm-return BelongsToMany<\App\Models\Role>
     * @psalm-suppress TooManyTemplateParams
     */
    public function roles(): BelongsToMany
    {
        /** @var BelongsToMany<\App\Models\Role,\App\Models\User> $rel */ // phpstan
        $rel = $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
        return $rel;
    }

    public function hasRole(string $roleName): bool
    {
        /** @var EloquentCollection<int,\App\Models\Role>|null $loaded */
        $loaded = $this->getRelationValue('roles');

        if ($loaded !== null) {
            foreach ($loaded as $role) {
                /** @var mixed $rn */
                $rn = $role->getAttribute('name');
                if (is_string($rn) && strcasecmp($rn, $roleName) === 0) {
                    return true;
                }
            }
        }

        /** @var BelongsToMany<\App\Models\Role,\App\Models\User> $rel */ // phpstan
        /** @psalm-suppress TooManyTemplateParams */
        $rel = $this->roles();

        /** @var EloquentBuilder<\App\Models\Role> $qb */
        $qb = $rel->getQuery();

        /** @var bool $exists */
        $exists = $qb->whereRaw('LOWER(name) = LOWER(?)', [$roleName])->exists();
        return $exists;
    }

    /**
     * @param array<int,string> $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $r) {
            if ($this->hasRole($r)) {
                return true;
            }
        }
        return false;
    }
}

