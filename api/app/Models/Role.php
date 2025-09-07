<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 */
final class Role extends Model
{
    protected $table = 'roles';

    /** @var array<int, string> */
    protected $fillable = ['id', 'name'];

    /**
     * @return BelongsToMany<\App\Models\User, \App\Models\Role>
     */
    public function users(): BelongsToMany
    {
        /** @var BelongsToMany<\App\Models\User, \App\Models\Role> $rel */
        $rel = $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
        return $rel;
    }
}
