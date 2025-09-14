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
                || (bool) config('core.rbac.persistence', false)
                || (string) config('core.rbac.mode', 'stub') === 'db');
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
            // Canonical event
            $logger->log([
                'actor_id'    => $actorId,
                'action'      => $action,              // rbac.user_role.replaced|attached|detached
                'category'    => 'RBAC',
                'entity_type' => 'user',
                'entity_id'   => (string) $target->id,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => $meta,
            ]);

            // Legacy aliases
            $alias = match ($action) {
                'rbac.user_role.attached'  => 'role.attach',
                'rbac.user_role.detached'  => 'role.detach',
                'rbac.user_role.replaced'  => 'role.replace',
                default                    => null,
            };

            if ($alias !== null) {
                $logger->log([
                    'actor_id'    => $actorId,
                    'action'      => $alias,
                    'category'    => 'RBAC',
                    'entity_type' => 'user',
                    'entity_id'   => (string) $target->id,
                    'ip'          => $request->ip(),
                    'ua'          => $request->userAgent(),
                    'meta'        => $meta,
                ]);
            }
        } catch (\Throwable) {
            // never fail API on audit issues
        }
    }

    private static function resolveRoleId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Prefer exact ID match first
        /** @var ?string $byId */
        $byId = Role::query()->whereKey($value)->value('id');
        if (is_string($byId) && $byId !== '') {
            return $byId;
        }

        // Fallback to exact name match
        /** @var ?string $byName */
        $byName = Role::query()->where('name', $value)->value('id');
        return is_string($byName) && $byName !== '' ? $byName : null;
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
            // allow empty array
            'roles'   => ['present', 'array'],
            'roles.*' => ['string', 'distinct', 'min:1', 'max:64'],
        ]);

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        /** @var array<int,string> $values */
        $values = array_values($payload['roles']);

        // Accept role IDs or names
        $ids = [];
        $missing = [];
        foreach ($values as $v) {
            $id = self::resolveRoleId($v);
            if ($id === null) {
                $missing[] = $v;
            } else {
                $ids[] = $id;
            }
        }

        if ($missing !== []) {
            return response()->json([
                'ok'    => false,
                'code'  => 'ROLE_NOT_FOUND',
                'roles' => array_values($missing),
            ], 422);
        }

        $u->roles()->sync(array_values(array_unique($ids)));

        $after   = $u->roles()->pluck('name')->sort()->values()->all();
        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $this->writeAudit($request, $u, 'rbac.user_role.replaced', [
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

        $roleId = self::resolveRoleId($role);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'roles' => [$role]], 422);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->syncWithoutDetaching([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if (!in_array(Role::query()->find($roleId)?->name ?? '', $before, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.attached', [
                'role_id' => $roleId,
                'before'  => $before,
                'after'   => $after,
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

        $roleId = self::resolveRoleId($role);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'roles' => [$role]], 422);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->detach([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if (in_array(Role::query()->find($roleId)?->name ?? '', $before, true) && !in_array(Role::query()->find($roleId)?->name ?? '', $after, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.detached', [
                'role_id' => $roleId,
                'before'  => $before,
                'after'   => $after,
            ]);
        }

        return response()->json([
            'ok'    => true,
            'user'  => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => array_values($after),
        ], 200);
    }
}
