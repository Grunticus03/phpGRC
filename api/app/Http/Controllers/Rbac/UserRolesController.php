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
    private function rbacActive(): bool
    {
        return (bool) config('core.rbac.enabled', false)
            && (string) config('core.rbac.mode', 'stub') === 'persist';
    }

    public function show(int $user): JsonResponse
    {
        if (!$this->rbacActive()) {
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

    public function replace(Request $request, int $user): JsonResponse
    {
        if (!$this->rbacActive()) {
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

        $u->roles()->sync($map->values()->all());

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($u->roles()->pluck('name')->all()),
        ], 200);
    }

    public function attach(int $user, string $role): JsonResponse
    {
        if (!$this->rbacActive()) {
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

        $u->roles()->syncWithoutDetaching([$roleId]);

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($u->roles()->pluck('name')->all()),
        ], 200);
    }

    public function detach(int $user, string $role): JsonResponse
    {
        if (!$this->rbacActive()) {
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
