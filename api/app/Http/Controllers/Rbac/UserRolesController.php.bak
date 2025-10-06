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
        /** @var mixed $enabledRaw */
        $enabledRaw = config('core.rbac.enabled');
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        /** @var mixed $modeRaw */
        $modeRaw = config('core.rbac.mode');
        $mode = is_string($modeRaw) ? $modeRaw : 'stub';

        /** @var mixed $persistRaw */
        $persistRaw = config('core.rbac.persistence');
        $persist = filter_var($persistRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        return $enabled && ($mode === 'persist' || $persist === true || $mode === 'db');
    }

    private function auditEnabled(): bool
    {
        return (bool) config('core.audit.enabled', true) && Schema::hasTable('audit_events');
    }

    /**
     * @param  non-empty-string  $action
     * @param  array<string,mixed>  $meta
     */
    private function writeAudit(Request $request, User $target, string $action, array $meta = []): void
    {
        if (! $this->auditEnabled()) {
            return;
        }

        /** @var AuditLogger $logger */
        $logger = app(AuditLogger::class);
        $actor = Auth::user();
        $actorId = ($actor instanceof User) ? $actor->id : null;

        $targetName = trim($target->name);
        $targetEmail = trim($target->email);

        $actorName = ($actor instanceof User) ? trim($actor->name) : '';
        $actorEmail = ($actor instanceof User) ? trim($actor->email) : '';

        /** @var non-empty-string $entityId */
        $entityId = $this->nes((string) $target->id);

        $metaWithContext = $meta;
        if (! isset($metaWithContext['target_username']) && $targetName !== '') {
            $metaWithContext['target_username'] = $targetName;
        }
        if (! isset($metaWithContext['target_email']) && $targetEmail !== '') {
            $metaWithContext['target_email'] = $targetEmail;
        }
        if (! isset($metaWithContext['actor_username']) && $actorName !== '') {
            $metaWithContext['actor_username'] = $actorName;
        }
        if (! isset($metaWithContext['actor_email']) && $actorEmail !== '') {
            $metaWithContext['actor_email'] = $actorEmail;
        }

        try {
            $logger->log([
                'actor_id' => $actorId,
                'action' => $action,
                'category' => 'RBAC',
                'entity_type' => 'user',
                'entity_id' => $entityId,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $metaWithContext,
            ]);
        } catch (\Throwable) {
            // swallow audit failures
        }
    }

    private static function normalizeRoleName(string $value): string
    {
        $trimmed = trim($value);

        return (string) preg_replace('/\s+/u', ' ', $trimmed);
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'The given data was invalid.',
            'code' => 'VALIDATION_FAILED',
            'errors' => [$field => [$message]],
        ], 422);
    }

    private static function resolveRoleId(string $value): ?string
    {
        $norm = self::normalizeRoleName($value);
        $canonical = self::canonicalRoleKey($value);

        foreach ($value === $norm ? [$value] : [$value, $norm] as $candidate) {
            /** @var null|string $byId */
            $byId = Role::query()->whereKey($candidate)->value('id');
            if (is_string($byId) && $byId !== '') {
                return $byId;
            }
        }

        /** @var null|string $byExact */
        $byExact = Role::query()->where('name', $norm)->value('id');
        if (is_string($byExact) && $byExact !== '') {
            return $byExact;
        }

        $target = mb_strtolower($norm, 'UTF-8');
        foreach (Role::query()->get(['id', 'name']) as $r) {
            $nameAttr = $r->getAttribute('name');
            $idAttr = $r->getAttribute('id');

            if (! is_string($nameAttr) || ! is_string($idAttr) || $idAttr === '') {
                continue;
            }

            $candidate = mb_strtolower(self::normalizeRoleName($nameAttr), 'UTF-8');
            if ($candidate === $target) {
                return $idAttr;
            }

            if ($canonical !== '') {
                if (self::canonicalRoleKey($idAttr) === $canonical) {
                    return $idAttr;
                }

                if (self::canonicalRoleKey($nameAttr) === $canonical) {
                    return $idAttr;
                }
            }
        }

        return null;
    }

    private static function canonicalRoleKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = (string) preg_replace('/[\p{Mn}]+/u', '', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $value);
        $value = (string) preg_replace('/[\s-]+/u', '_', $value);
        $value = (string) preg_replace('/_+/u', '_', $value);
        $value = trim($value, '_');

        if ($value === '') {
            return '';
        }

        return mb_strtolower($value, 'UTF-8');
    }

    public function show(int $user): JsonResponse
    {
        if (! $this->rbacActive()) {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var list<string> $roles */
        $roles = $u->roles()->pluck('name')->values()->all();

        return response()->json([
            'ok' => true,
            'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => $roles,
        ], 200);
    }

    public function replace(Request $request, int $user): JsonResponse
    {
        if (! $this->rbacActive()) {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        /** @var array{roles:list<string>} $payload */
        $payload = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'min:2', 'max:64', 'regex:/^[\p{L}\p{N}_-]{2,64}$/u'],
        ]);

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var list<string> $before */
        $before = $u->roles()->pluck('name')->sort()->values()->all();

        /** @var list<string> $values */
        $values = array_map('strval', $payload['roles']);
        /** @var array<string, true> $normSeen */
        $normSeen = [];
        /** @var list<string> $ids */
        $ids = [];
        /** @var list<string> $missing */
        $missing = [];

        foreach ($values as $v) {
            $norm = self::normalizeRoleName($v);
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
                $missing[] = $v;
            } else {
                $ids[] = $id;
            }
        }

        if ($missing !== []) {
            return response()->json([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => $missing,
            ], 422);
        }

        $u->roles()->sync(array_values(array_unique($ids)));

        /** @var list<string> $after */
        $after = $u->roles()->pluck('name')->sort()->values()->all();
        /** @var list<string> $added */
        $added = array_values(array_diff($after, $before));
        /** @var list<string> $removed */
        $removed = array_values(array_diff($before, $after));

        $this->writeAudit($request, $u, 'rbac.user_role.replaced', [
            'before' => $before,
            'after' => $after,
            'added' => $added,
            'removed' => $removed,
        ]);

        return response()->json([
            'ok' => true,
            'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => $after,
        ], 200);
    }

    public function attach(Request $request, int $user, string $role): JsonResponse
    {
        if (! $this->rbacActive()) {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        $norm = self::normalizeRoleName($role);
        if (! preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $norm)) {
            return $this->validationError('role', 'Role name may contain only letters, numbers, underscores, and hyphens.');
        }

        $roleId = self::resolveRoleId($norm);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'missing_roles' => [$role]], 422);
        }

        /** @var null|string $rawName */
        $rawName = Role::query()->whereKey($roleId)->value('name');
        $roleName = is_string($rawName) ? $rawName : '';

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var list<string> $before */
        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->syncWithoutDetaching([$roleId]);

        /** @var list<string> $after */
        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if ($roleName !== '' && ! in_array($roleName, $before, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.attached', [
                'role' => $roleName,
                'role_id' => $roleId,
                'before' => $before,
                'after' => $after,
            ]);
        }

        return response()->json([
            'ok' => true,
            'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => $after,
        ], 200);
    }

    public function detach(Request $request, int $user, string $role): JsonResponse
    {
        if (! $this->rbacActive()) {
            return response()->json(['ok' => false, 'code' => 'RBAC_DISABLED'], 404);
        }

        $norm = self::normalizeRoleName($role);
        if (! preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $norm)) {
            return $this->validationError('role', 'Role name may contain only letters, numbers, underscores, and hyphens.');
        }

        $roleId = self::resolveRoleId($norm);
        if ($roleId === null) {
            return response()->json(['ok' => false, 'code' => 'ROLE_NOT_FOUND', 'missing_roles' => [$role]], 422);
        }

        /** @var null|string $rawName */
        $rawName = Role::query()->whereKey($roleId)->value('name');
        $roleName = is_string($rawName) ? $rawName : '';

        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var list<string> $before */
        $before = $u->roles()->pluck('name')->sort()->values()->all();

        $u->roles()->detach([$roleId]);

        /** @var list<string> $after */
        $after = $u->roles()->pluck('name')->sort()->values()->all();

        if ($roleName !== '' && in_array($roleName, $before, true) && ! in_array($roleName, $after, true)) {
            $this->writeAudit($request, $u, 'rbac.user_role.detached', [
                'role' => $roleName,
                'role_id' => $roleId,
                'before' => $before,
                'after' => $after,
            ]);
        }

        return response()->json([
            'ok' => true,
            'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            'roles' => $after,
        ], 200);
    }

    /**
     * @return non-empty-string
     */
    private function nes(string $s): string
    {
        if ($s === '') {
            throw new \LogicException('Expected non-empty string');
        }

        return $s;
    }
}
