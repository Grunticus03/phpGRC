<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property-read EloquentCollection<int,\App\Models\Role> $roles
 *
 * @use \Laravel\Sanctum\HasApiTokens<\Laravel\Sanctum\PersonalAccessToken>
 *
 * @psalm-suppress MissingTemplateParam
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    /**
     * Allow tests using `User::factory()` without HasFactory generics.
     *
     * @param  array<string,mixed>  $state
     */
    public static function factory(?int $count = null, array $state = []): UserFactory
    {
        $factory = UserFactory::new();
        if ($count !== null) {
            $factory = $factory->count($count);
        }
        if (! empty($state)) {
            /** @var array<string,mixed> $stateArr */
            $stateArr = $state;
            $factory = $factory->state($stateArr);
        }

        return $factory;
    }

    protected $hidden = ['password', 'remember_token'];

    /**
     * @phpstan-return BelongsToMany<\App\Models\Role, \App\Models\User>
     *
     * @psalm-return BelongsToMany<\App\Models\Role>
     *
     * @psalm-suppress TooManyTemplateParams
     */
    public function roles(): BelongsToMany
    {
        /** @var BelongsToMany<\App\Models\Role, \App\Models\User> $rel */
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

        /** @psalm-suppress TooManyTemplateParams */
        $rel = $this->roles();

        /** @var EloquentBuilder<\App\Models\Role> $qb */
        $qb = $rel->getQuery();

        return $qb->whereRaw('LOWER(name) = LOWER(?)', [$roleName])->exists();
    }

    /**
     * @param  array<int,string>  $roles
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
