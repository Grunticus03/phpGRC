<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Requests\Rbac\StoreRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RolesController extends Controller
{
    public function index(): JsonResponse
    {
        // Preserve Phase-4 response shape: list of role names.
        $names = [];

        if (Schema::hasTable('roles')) {
            /** @var array<int,string> $names */
            $names = Role::query()->orderBy('name')->pluck('name')->all();
        }

        if ($names === []) {
            /** @var array<int,string> $fallback */
            $fallback = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            $names    = array_values($fallback);
        }

        return response()->json([
            'ok'    => true,
            'roles' => $names,
        ], 200);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $name = $request->validated('name');

        // Deterministic ID. Ensure uniqueness if slug collides.
        $base = 'role_' . Str::slug($name, '_');
        $id   = $base;
        $i    = 1;

        while (Role::query()->whereKey($id)->exists()) {
            $i++;
            $id = $base . '_' . $i;
        }

        $role = new Role([
            'id'   => $id,
            'name' => $name,
        ]);
        $role->save();

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }
}

