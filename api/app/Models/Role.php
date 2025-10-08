<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 * @property-read \Illuminate\Database\Eloquent\Collection<int,\App\Models\User> $users
 */
final class Role extends Model
{
    protected $table = 'roles';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name'];

    /**
     * @phpstan-return BelongsToMany<\App\Models\User, \App\Models\Role>
     *
     * @psalm-return BelongsToMany<\App\Models\User>
     *
     * @psalm-suppress TooManyTemplateParams
     */
    public function users(): BelongsToMany
    {
        /** @var BelongsToMany<\App\Models\User, \App\Models\Role> $rel */
        $rel = $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');

        return $rel;
    }
}
