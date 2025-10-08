<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Collect admin users and their most recent successful login timestamp.
 *
 * @psalm-type AdminRow=array{
 *   id:int,
 *   name:string,
 *   email:string,
 *   last_login_at: string|null
 * }
 * @psalm-type OutputShape=array{admins:array<int,AdminRow>}
 */
final class AdminActivityCalculator
{
    /**
     * @return OutputShape
     */
    public function compute(): array
    {
        /** @var list<string> $adminRoleIds */
        $adminRoleIds = Role::query()
            ->whereRaw('LOWER(name) = ?', ['admin'])
            ->pluck('id')
            ->filter(static fn ($id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($adminRoleIds === []) {
            return ['admins' => []];
        }

        /** @var list<int> $userIds */
        $userIds = User::query()
            ->select('users.id')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->whereIn('role_user.role_id', $adminRoleIds)
            ->distinct()
            ->pluck('users.id')
            ->filter(static fn ($id): bool => is_int($id))
            ->values()
            ->all();

        if ($userIds === []) {
            return ['admins' => []];
        }

        /** @var \Illuminate\Database\Eloquent\Builder<AuditEvent> $loginQuery */
        $loginQuery = AuditEvent::query()
            ->whereIn('category', ['AUTH'])
            ->whereIn('action', ['auth.login'])
            ->whereIn('actor_id', $userIds);

        /** @var array<int,string|null> $lastLoginIso */
        $lastLoginIso = $loginQuery
            ->selectRaw('actor_id, MAX(occurred_at) as last_login_at')
            ->groupBy('actor_id')
            ->pluck('last_login_at', 'actor_id')
            ->map(static function ($ts): ?string {
                if (! is_string($ts) || $ts === '') {
                    return null;
                }
                try {
                    return CarbonImmutable::parse($ts)->setTimezone('UTC')->toIso8601String();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->all();

        /** @var \Illuminate\Database\Eloquent\Builder<User> $adminQuery */
        $adminQuery = User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->whereIn('role_user.role_id', $adminRoleIds)
            ->whereIn('users.id', $userIds)
            ->distinct()
            ->orderBy('users.name');

        /** @var array<int,AdminRow> $admins */
        $admins = [];
        foreach ($adminQuery->get() as $user) {
            /** @var mixed $key */
            $key = $user->getKey();
            $id = is_int($key) ? $key : (is_numeric($key) ? (int) $key : 0);
            $admins[] = [
                'id' => $id,
                'name' => $user->name,
                'email' => $user->email,
                'last_login_at' => $lastLoginIso[$id] ?? null,
            ];
        }

        return ['admins' => $admins];
    }
}
