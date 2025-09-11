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
    private function persistenceEnabled(): bool
    {
        /** @var bool $flag */
        $flag = (bool) config('core.rbac.persistence', false);
        /** @var mixed $mode */
        $mode = config('core.rbac.mode');
        return $flag || $mode === 'persist';
    }

    public function index(): JsonResponse
    {
        if (!$this->persistenceEnabled()) {
            /** @var array<int,string> $cfg */
            $cfg = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            return response()->json([
                'ok'    => true,
                'roles' => array_values($cfg),
            ], 200);
        }

        /** @var array<int,string> $names */
        $names = [];
        if (Schema::hasTable('roles')) {
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
        // Phase 4 default: stub-only unless persistence is explicitly enabled.
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'       => false,
                'note'     => 'stub-only',
                'accepted' => ['name' => $request->string('name')->toString()],
            ], 202);
        }

        $name = $request->validated('name');

        // Deterministic slug ID with collision suffixing.
        $base = 'role_' . Str::slug($name, '_');
        $id   = $base;
        $i    = 1;
        while (Role::query()->whereKey($id)->exists()) {
            $i++;
            $id = $base . '_' . $i;
        }

        $role = new Role(['id' => $id, 'name' => $name]);
        $role->save();

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }
}

