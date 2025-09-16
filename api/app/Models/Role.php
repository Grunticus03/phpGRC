<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    protected $table = 'roles';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $fillable = ['id', 'name'];

    /**
     * @return BelongsToMany
     * @psalm-return BelongsToMany<\App\Models\User>
     * @phpstan-return BelongsToMany<\App\Models\User, \App\Models\Role>
     */
    public function users(): BelongsToMany
    {
        /** @var BelongsToMany $rel */
        /** @psalm-var BelongsToMany<\App\Models\User> $rel */
        /** @phpstan-var BelongsToMany<\App\Models\User, \App\Models\Role> $rel */
        $rel = $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
        return $rel;
    }
}

