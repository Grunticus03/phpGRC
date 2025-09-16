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

    /**
     * @param array<string,mixed> $meta
     */
    private function writeAudit(Request $request, User $target, string $action, array $meta = []): void
    {
        if (!$this->auditEnabled()) {
            return;
        }

        /** @var AuditLogger $logger */
        $logger  = app(AuditLogger::class);
        $actor   = Auth::user();
        $actorId = ($actor instanceof User) ? $actor->id : null;

        try {
            $logger->log([
                'actor_id'    => $actorId,
                'action'      => $action,
                'category'    => 'RBAC',
                'entity_type' => 'user',
                'entity_id'   => (string) $target->id,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => $meta,
            ]);

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
            // swallow audit failures
        }
    }

    private static function normalizeRoleName(string $value): string
    {
        // Trim and collapse internal whitespace. Preserve punctuation.
        $trimmed = trim($value);
        return (string) preg_replace('/\s+/u', ' ', $trimmed);
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'The given data was invalid.',
            'code'    => 'VALIDATION_FAILED',
            'errors'  => [$field => [$message]],
        ], 422);
    }

    private static function resolveRoleId(string $value): ?string
    {
        // Accept ids, exact names, and names matched case-insensitively with whitespace normalization.
        $norm = self::normalizeRoleName($value);

        // Try primary key by raw and normalized
        foreach ([$value, $norm] as $candidate) {
            $byId = Role::query()->whereKey($candidate)->value('id');
            if (is_string($byId) && $byId !== '') {
                return $byId;
            }
        }

        // Exact name match first
        $byExact = Role::query()->where('name', $norm)->value('id');
        if (is_string($byExact) && $byExact !== '') {
            return $byExact;
        }

        // Case-insensitive + collapsed-space comparison
        $target = mb_strtolower($norm, 'UTF-8');
        foreach (Role::query()->get(['id', 'name']) as $r) {
            $candidate = mb_strtolower(self::normalizeRoleName((string) $r->name), 'UTF-8');
            if ($candidate === $target) {
                return (string) $r->id;
            }
        }

        return null;
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
            'roles'   => ['present', 'array'],
            'roles.*' => ['string', 'distinct', 'min:2', 'max:64'],
        ]);

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        // Normalize names and dedupe after normalization
        $values   = array_values($payload['roles']);
        $normSeen = [];
        $ids      = [];
        $missing  = [];

        foreach ($values as $v) {
            $norm = self::normalizeRoleName((string) $v);
            // enforce max length after normalization
            $len = mb_strlen($norm, 'UTF-8');
            if ($len < 2 || $len > 64) {
                return $this->validationError('roles', 'Each role must be between 2 and 64 characters after normalization.');
            }
            $key = mb_strtolower($norm, 'UTF-8');
            if (isset($normSeen[$key])) {
                return $this->validationError('roles', 'Duplicate roles after normalization.');
            }
            $normSeen[$key] = true;

            $id = self::resolveRoleId($norm);
            if ($id === null) {
                $missing[] = $v; // report original input
            } else {
                $ids[] = $id;
            }
        }

        if ($missing !== []) {
            return response()->json([
                'ok'            => false,
                'code'          => 'ROLE_NOT_FOUND',
                'missing_roles' => array_values($missing),
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

        $norm = self::normalizeRoleName($role);
        $len  = mb_strlen($norm, 'UTF-8');
        if ($len < 2 || $len > 64) {
            return $this->validationError('role', 'Role name must be between 2 and 64 characters after normalization.');
        }

        $roleId = self::resolveRoleId($norm);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'missing_roles' => [$role]], 422);
        }

        /** @var Role|null $roleModel */
        $roleModel = Role::query()->find($roleId);
        $roleName  = $roleModel?->name ?? '';

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->syncWithoutDetaching([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if (!in_array($roleName, $before, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.attached', [
                'role'    => $roleName,
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

        $norm = self::normalizeRoleName($role);
        $len  = mb_strlen($norm, 'UTF-8');
        if ($len < 2 || $len > 64) {
            return $this->validationError('role', 'Role name must be between 2 and 64 characters after normalization.');
        }

        $roleId = self::resolveRoleId($norm);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'missing_roles' => [$role]], 422);
        }

        /** @var Role|null $roleModel */
        $roleModel = Role::query()->find($roleId);
        $roleName  = $roleModel?->name ?? '';

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->detach([$roleId]);

        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if (in_array($roleName, $before, true) && !in_array($roleName, $after, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.detached', [
                'role'    => $roleName,
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

