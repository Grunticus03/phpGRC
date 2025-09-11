<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class UserRolesController extends Controller
{
    /**
     * GET /api/rbac/users/{user}/roles
     */
    public function show(int $user): JsonResponse
    {
        if (!(bool) config('core.rbac.enabled', false) || (string) config('core.rbac.mode', 'db') !== 'db') {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $roles = $u->roles()->pluck('name')->values()->all();

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => $roles,
        ], 200);
    }

    /**
     * PUT /api/rbac/users/{user}/roles
     * Replace the user's roles with the provided set.
     * Body: { "roles": ["Admin","Auditor"] }
     */
    public function replace(Request $request, int $user): JsonResponse
    {
        if (!(bool) config('core.rbac.enabled', false) || (string) config('core.rbac.mode', 'db') !== 'db') {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        $payload = $request->validate([
            'roles'   => ['required', 'array', 'min:0'],
            'roles.*' => ['string', 'distinct', 'min:1', 'max:64'],
        ]);

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $names = array_values($payload['roles']);

        $map = Role::query()
            ->whereIn('name', $names)
            ->pluck('id', 'name'); // name => id

        $missing = array_values(array_diff($names, $map->keys()->all()));
        if ($missing !== []) {
            return response()->json([
                'ok'    => false,
                'code'  => 'ROLE_NOT_FOUND',
                'roles' => $missing,
            ], 422);
        }

        // Sync by numeric IDs per FK role_user.role_id -> roles.id
        $u->roles()->sync($map->values()->all());

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($u->roles()->pluck('name')->all()),
        ], 200);
    }

    /**
     * POST /api/rbac/users/{user}/roles/{role}
     * Attach a single role by name if it exists.
     */
    public function attach(int $user, string $role): JsonResponse
    {
        if (!(bool) config('core.rbac.enabled', false) || (string) config('core.rbac.mode', 'db') !== 'db') {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        $name = trim($role);
        if ($name === '') {
            return response()->json(['ok' => false, 'code' => 'ROLE_NAME_INVALID'], 422);
        }

        $roleId = Role::query()->where('name', $name)->value('id');
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'roles' => [$name]], 422);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        // Ensure idempotent attach by numeric role id
        $u->roles()->syncWithoutDetaching([$roleId]);

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($u->roles()->pluck('name')->all()),
        ], 200);
    }

    /**
     * DELETE /api/rbac/users/{user}/roles/{role}
     * Detach a single role by name if present.
     */
    public function detach(int $user, string $role): JsonResponse
    {
        if (!(bool) config('core.rbac.enabled', false) || (string) config('core.rbac.mode', 'db') !== 'db') {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        $name = trim($role);
        if ($name === '') {
            return response()->json(['ok' => false, 'code' => 'ROLE_NAME_INVALID'], 422);
        }

        $roleId = Role::query()->where('name', $name)->value('id');
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'roles' => [$name]], 422);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $u->roles()->detach([$roleId]);

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($u->roles()->pluck('name')->all()),
        ], 200);
    }
}
