<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Requests\Rbac\RoleCreateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

use function array_key_exists;

final class RolesController extends Controller
{
    private bool $seedDefaultsEnsured = false;

    private function persistenceEnabled(): bool
    {
        /** @var mixed $flagRaw */
        $flagRaw = config('core.rbac.persistence');
        $flag = filter_var($flagRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        /** @var mixed $modeRaw */
        $modeRaw = config('core.rbac.mode');
        $mode = is_string($modeRaw) ? $modeRaw : 'stub';

        return $flag || $mode === 'persist' || $mode === 'db';
    }

    private function ensureSeedDefaults(): void
    {
        if ($this->seedDefaultsEnsured) {
            return;
        }

        if (! Schema::hasTable('roles')) {
            $this->seedDefaultsEnsured = true;

            return;
        }

        if (Role::query()->count() > 0) {
            $this->seedDefaultsEnsured = true;

            return;
        }

        try {
            (new RolesSeeder)->run();
        } catch (\Throwable) {
            return;
        }

        $this->seedDefaultsEnsured = true;
    }

    public function index(): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            /** @var array<array-key, mixed> $cfgArr */
            $cfgArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            $roles = $this->canonicalizeRoleNames($cfgArr);

            if ($roles === []) {
                $roles = ['admin', 'auditor', 'risk_manager', 'user'];
            }

            return response()->json(['ok' => true, 'roles' => $roles], 200);
        }

        /** @var list<string> $names */
        $names = [];
        if (Schema::hasTable('roles')) {
            /** @var list<string> $namesFromDb */
            $namesFromDb = Role::query()
                ->orderBy('name')
                ->pluck('name')
                ->all();
            $names = $this->canonicalizeRoleNames($namesFromDb);
        }

        if ($names === []) {
            /** @var array<array-key, mixed> $fallbackArr */
            $fallbackArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            $names = $this->canonicalizeRoleNames($fallbackArr);

            if ($names === []) {
                $names = ['admin', 'auditor', 'risk_manager', 'user'];
            }
        }

        return response()->json(['ok' => true, 'roles' => $names], 200);
    }

    public function store(RoleCreateRequest $request): JsonResponse
    {
        if (! $this->persistenceEnabled() || ! Schema::hasTable('roles')) {
            $raw = $request->string('name')->toString();
            $collapsed = $this->normalizeRoleName($raw);
            $canonical = $this->canonicalRoleKey($collapsed);

            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'accepted' => ['name' => $canonical !== '' ? $canonical : $collapsed],
            ], 202);
        }

        $this->ensureSeedDefaults();

        $normalized = $this->normalizeRoleNameInput($request->string('name')->toString());
        if ($normalized instanceof JsonResponse) {
            return $normalized;
        }

        $canonical = $normalized['canonical'];
        $display = $normalized['display'];

        $baseId = 'role_'.$canonical;
        $id = $baseId;
        if (Role::query()->whereKey($id)->exists()) {
            $suffix = 1;
            while (Role::query()->whereKey($baseId.'_'.$suffix)->exists()) {
                $suffix++;
            }
            $id = $baseId.'_'.$suffix;
        }

        $role = Role::query()->create(['id' => $id, 'name' => $canonical]);

        $this->logRoleAudit($request, 'rbac.role.created', $role, [
            'role' => $canonical,
            'name' => $canonical,
            'name_normalized' => $canonical,
            'role_label' => $display,
        ]);

        return response()->json([
            'ok' => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        if (! $this->persistenceEnabled() || ! Schema::hasTable('roles')) {
            $raw = $request->input('name');
            $accepted = null;
            if (is_string($raw)) {
                $collapsed = $this->normalizeRoleName($raw);
                $canonical = $this->canonicalRoleKey($collapsed);
                $accepted = $canonical !== '' ? $canonical : $collapsed;
            }

            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'accepted' => [
                    'role' => $role,
                    'name' => $accepted,
                ],
            ], 202);
        }

        $target = $this->resolveRoleModel($role);
        if (! $target instanceof Role) {
            return response()->json([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => [$role],
            ], 404);
        }

        /** @var mixed $raw */
        $raw = $request->input('name');
        if (! is_string($raw)) {
            return $this->validationFailed(['name' => ['Role name is required.']]);
        }

        $normalized = $this->normalizeRoleNameInput($raw, $target);
        if ($normalized instanceof JsonResponse) {
            return $normalized;
        }

        $previousName = $target->name;
        $canonical = $normalized['canonical'];
        $display = $normalized['display'];

        if ($previousName === $canonical) {
            return response()->json([
                'ok' => true,
                'role' => ['id' => $target->id, 'name' => $target->name],
                'note' => 'unchanged',
            ], 200);
        }

        $target->name = $canonical;
        $target->save();

        $this->logRoleAudit($request, 'rbac.role.updated', $target, [
            'role' => $canonical,
            'name' => $canonical,
            'name_normalized' => $canonical,
            'role_label' => $display,
            'name_previous' => $previousName,
        ]);

        return response()->json([
            'ok' => true,
            'role' => ['id' => $target->id, 'name' => $target->name],
        ], 200);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        if (! $this->persistenceEnabled() || ! Schema::hasTable('roles')) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
            ], 202);
        }

        $target = $this->resolveRoleModel($role);
        if (! $target instanceof Role) {
            return response()->json([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => [$role],
            ], 404);
        }

        $meta = [
            'role' => $target->name,
            'name' => $target->name,
            'role_id' => $target->id,
            'name_normalized' => $target->name,
            'role_label' => $this->humanizeRole($target->name),
        ];

        $this->logRoleAudit($request, 'rbac.role.deleted', $target, $meta);

        $target->delete();

        return response()->json(['ok' => true], 200);
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

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'VALIDATION_FAILED',
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ], 422);
    }

    /**
     * @return array{display:string, canonical:string}|JsonResponse
     */
    private function normalizeRoleNameInput(string $raw, ?Role $ignore = null): array|JsonResponse
    {
        $collapsed = $this->normalizeRoleName($raw);
        if ($collapsed === '') {
            return $this->validationFailed(['name' => ['Role name is required.']]);
        }

        $canonical = $this->canonicalRoleKey($collapsed);
        if ($canonical === '') {
            return $this->validationFailed(['name' => ['Role name must contain letters or numbers.']]);
        }

        $length = mb_strlen($canonical, 'UTF-8');
        if ($length < 2 || $length > 64) {
            return $this->validationFailed(['name' => ['Role name must normalize to between 2 and 64 characters.']]);
        }

        $query = Role::query()->whereRaw('LOWER(name) = ?', [$canonical]);
        if ($ignore instanceof Role) {
            /** @var mixed $ignoreId */
            $ignoreId = $ignore->getAttribute('id');
            if (is_string($ignoreId) && $ignoreId !== '') {
                $query->where('id', '!=', $ignoreId);
            }
        }

        if ($query->exists()) {
            return $this->validationFailed(['name' => ['Role already exists after normalization.']]);
        }

        return ['display' => $this->humanizeRole($canonical), 'canonical' => $canonical];
    }

    private function normalizeRoleName(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function canonicalizeRoleNames(iterable $values): array
    {
        /** @var array<string, true> $unique */
        $unique = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $canonical = $this->canonicalRoleKey($value);
            if ($canonical === '') {
                continue;
            }

            $unique[$canonical] = true;
        }

        $names = array_keys($unique);
        sort($names, SORT_STRING);

        return $names;
    }

    private function humanizeRole(string $canonical): string
    {
        if ($canonical === '') {
            return '';
        }

        $withSpaces = str_replace('_', ' ', $canonical);

        return ucwords($withSpaces);
    }

    private function canonicalRoleKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (class_exists('\\Normalizer')) {
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

    private function resolveRoleModel(string $value): ?Role
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $variants = [$trimmed];
        $normalized = $this->normalizeRoleName($trimmed);
        if ($normalized !== $trimmed) {
            $variants[] = $normalized;
        }

        foreach ($variants as $variant) {
            /** @var Role|null $byId */
            $byId = Role::query()->whereKey($variant)->first();
            if ($byId instanceof Role) {
                return $byId;
            }
        }

        $lower = mb_strtolower($normalized, 'UTF-8');
        /** @var Role|null $byName */
        $byName = Role::query()->whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($byName instanceof Role) {
            return $byName;
        }

        $canonical = $this->canonicalRoleKey($value);
        if ($canonical === '') {
            return null;
        }

        foreach (Role::query()->get(['id', 'name']) as $candidate) {
            $idAttr = $candidate->getAttribute('id');
            $nameAttr = $candidate->getAttribute('name');

            if (! is_string($idAttr) || ! is_string($nameAttr)) {
                continue;
            }

            if ($this->canonicalRoleKey($idAttr) === $canonical || $this->canonicalRoleKey($nameAttr) === $canonical) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  non-empty-string  $action
     * @param  array<string, mixed>  $meta
     */
    private function logRoleAudit(Request $request, string $action, Role $role, array $meta = []): void
    {
        try {
            /** @var AuditLogger $logger */
            $logger = app(AuditLogger::class);
            $actor = Auth::user();
            $actorId = Auth::id();

            $actorName = ($actor instanceof User) ? trim($actor->name) : '';
            $actorEmail = ($actor instanceof User) ? trim($actor->email) : '';

            $metaWithActor = $meta;
            if ($actorName !== '' && ! array_key_exists('actor_username', $metaWithActor)) {
                $metaWithActor['actor_username'] = $actorName;
            }
            if ($actorEmail !== '' && ! array_key_exists('actor_email', $metaWithActor)) {
                $metaWithActor['actor_email'] = $actorEmail;
            }

            $roleId = $role->getAttribute('id');
            if (! is_string($roleId) || $roleId === '') {
                throw new \LogicException('Role id must be a non-empty string.');
            }

            $logger->log([
                'actor_id' => is_int($actorId) ? $actorId : null,
                'action' => $action,
                'category' => 'RBAC',
                'entity_type' => 'role',
                'entity_id' => $this->nes($roleId),
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $metaWithActor,
            ]);
        } catch (\Throwable) {
            // ignore audit errors
        }
    }
}
