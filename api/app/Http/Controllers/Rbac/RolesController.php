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
        $flag = (bool) config('core.rbac.persistence', false);
        $mode = (string) config('core.rbac.mode', 'stub');
        return $flag || $mode === 'persist' || $mode === 'db';
    }

    public function index(): JsonResponse
    {
        if (!$this->persistenceEnabled()) {
            /** @var array<mixed> $cfgArr */
            $cfgArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $cfg */
            $cfg = [];
            /** @var mixed $item */
            foreach ($cfgArr as $item) {
                if (is_string($item)) {
                    $cfg[] = $item;
                }
            }

            if ($cfg === []) {
                $cfg = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }

            return response()->json([
                'ok'    => true,
                'roles' => $cfg,
            ], 200);
        }

        /** @var list<string> $names */
        $names = [];
        if (Schema::hasTable('roles')) {
            /** @var list<string> $namesFromDb */
            $namesFromDb = Role::query()
                ->orderBy('name')
                ->pluck('name')
                ->filter(static fn ($v): bool => is_string($v))
                ->values()
                ->all();
            $names = $namesFromDb;
        }
        if ($names === []) {
            /** @var array<mixed> $fallbackArr */
            $fallbackArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $fallback */
            $fallback = [];
            /** @var mixed $item */
            foreach ($fallbackArr as $item) {
                if (is_string($item)) {
                    $fallback[] = $item;
                }
            }

            if ($fallback === []) {
                $fallback = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }
            $names = $fallback;
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

        // Name is validated by the FormRequest; force string to avoid mixed.
        $name = $request->string('name')->toString();

        // Base slug: role_<slug>
        $base = 'role_' . Str::slug($name, '_');

        // If base exists, find the highest suffix and increment.
        if (Role::query()->whereKey($base)->exists()) {
            /** @var list<string> $siblings */
            $siblings = Role::query()
                ->where('id', 'like', $base . '\_%')
                ->pluck('id')
                ->filter(static fn ($v): bool => is_string($v))
                ->values()
                ->all();

            $max = 0;
            foreach ($siblings as $sibId) {
                if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $sibId, $m) === 1) {
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
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
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

