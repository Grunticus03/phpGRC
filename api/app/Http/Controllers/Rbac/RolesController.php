<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Requests\Rbac\StoreRoleRequest;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
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
        return $flag || $mode === 'persist' || $mode === 'db';
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
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'       => false,
                'note'     => 'stub-only',
                'accepted' => ['name' => $request->string('name')->toString()],
            ], 202);
        }

        $name = $request->validated('name');

        // Base slug: role_<slug>
        $base = 'role_' . Str::slug($name, '_');

        // If base exists, find the highest suffix and increment.
        if (Role::query()->whereKey($base)->exists()) {
            $siblings = Role::query()
                ->where('id', 'like', $base . '\_%')
                ->pluck('id')
                ->all();

            $max = 0;
            foreach ($siblings as $id) {
                if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $id, $m)) {
                    $n = (int) $m[1];
                    if ($n > $max) {
                        $max = $n;
                    }
                }
            }
            $id = $base . '_' . ($max + 1);
        } else {
            $id = $base;
        }

        $role = Role::query()->create(['id' => $id, 'name' => $name]);

        // Audit
        try {
            /** @var AuditLogger $logger */
            $logger  = app(AuditLogger::class);
            $actorId = Auth::id();

            $logger->log([
                'actor_id'    => is_int($actorId) ? $actorId : null,
                'action'      => 'rbac.role.created',
                'category'    => 'RBAC',
                'entity_type' => 'role',
                'entity_id'   => $role->id,
                'ip'          => request()->ip(),
                'ua'          => request()->userAgent(),
                'meta'        => ['name' => $role->name],
            ]);
        } catch (\Throwable) {
            // never fail on audit
        }

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }
}
