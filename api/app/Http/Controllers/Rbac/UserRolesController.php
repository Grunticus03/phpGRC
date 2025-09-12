<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

final class UserRolesController extends Controller
{
    private function rbacActive(): bool
    {
        return (bool) config('core.rbac.enabled', false)
            && ((string) config('core.rbac.mode', 'stub') === 'persist'
                || (bool) config('core.rbac.persistence', false));
    }

    private function auditEnabled(): bool
    {
        return (bool) config('core.audit.enabled', true) && Schema::hasTable('audit_events');
    }

    private function writeAudit(Request $request, User $target, string $action, array $meta = []): void
    {
        if (!$this->auditEnabled()) {
            return;
        }

        /** @var AuditLogger $logger */
        $logger = app(AuditLogger::class);

        $actor = Auth::user();
        $actorId = ($actor instanceof User) ? $actor->id : null;

        try {
            $logger->log([
                'actor_id'    => $actorId,
                'action'      => $action,              // role.replace | role.attach | role.detach
                'category'    => 'RBAC',
                'entity_type' => 'user',
                'entity_id'   => (string) $target->id,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => $meta,
            ]);
        } catch (\Throwable) {
            // Never fail the API due to audit issues.
        }
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

        $before = $u->roles()->pluck('name')->sort()->values()->all();

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

        $after   = $u->roles()->pluck('name')->sort()->values()->all();
        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $this->writeAudit($request, $u, 'role.replace', [
            'before'  => $before,
            'after'   => $after,
            'added'   => $added,
            'removed' => $removed,
        ]);

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($after),
        ], 200);
    }

    public function attach(Request $request, int $user, string $role): JsonResponse
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

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->syncWithoutDetaching([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        // Only log if it actually changed.
        if (!in_array($name, $before, true)) {
            $this->writeAudit($request, $u, 'role.attach', [
                'role'   => $name,
                'before' => $before,
                'after'  => $after,
            ]);
        }

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($after),
        ], 200);
    }

    public function detach(Request $request, int $user, string $role): JsonResponse
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

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->detach([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        // Only log if it actually changed.
        if (in_array($name, $before, true) && !in_array($name, $after, true)) {
            $this->writeAudit($request, $u, 'role.detach', [
                'role'   => $name,
                'before' => $before,
                'after'  => $after,
            ]);
        }

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($after),
        ], 200);
    }
}

