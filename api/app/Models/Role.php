<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    use HasUlids;

    protected $table = 'roles';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $fillable = ['name']; // id is generated automatically

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, \App\Models\Role>
     */
    public function users(): BelongsToMany
    {
        /** @var \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, \App\Models\Role> $rel */
        $rel = $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
        return $rel;
    }
}
