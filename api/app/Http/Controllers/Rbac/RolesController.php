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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

        if (!Schema::hasTable('roles')) {
            $this->seedDefaultsEnsured = true;
            return;
        }

        if (Role::query()->count() > 0) {
            $this->seedDefaultsEnsured = true;
            return;
        }

        try {
            (new RolesSeeder())->run();
        } catch (\Throwable) {
            return;
        }

        $this->seedDefaultsEnsured = true;
    }

    public function index(): JsonResponse
    {
        if (!$this->persistenceEnabled()) {
            /** @var array<array-key, mixed> $cfgArr */
            $cfgArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $cfg */
            $cfg = array_values(array_filter(
                array_map(
                    static fn (mixed $v): ?string => is_string($v) ? $v : null,
                    $cfgArr
                ),
                static fn (?string $v): bool => $v !== null
            ));

            if ($cfg === []) {
                $cfg = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }

            return response()->json(['ok' => true, 'roles' => $cfg], 200);
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
            /** @var array<array-key, mixed> $fallbackArr */
            $fallbackArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $fallback */
            $fallback = array_values(array_filter(
                array_map(
                    static fn (mixed $v): ?string => is_string($v) ? $v : null,
                    $fallbackArr
                ),
                static fn (?string $v): bool => $v !== null
            ));

            if ($fallback === []) {
                $fallback = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }
            $names = $fallback;
        }

        return response()->json(['ok' => true, 'roles' => $names], 200);
    }

    public function store(RoleCreateRequest $request): JsonResponse
    {
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'       => true,
                'note'     => 'stub-only',
                'accepted' => ['name' => $request->string('name')->toString()],
            ], 202);
        }

        $this->ensureSeedDefaults();

        $raw = $request->string('name')->toString();
        $trimmed = trim($raw);
        /** @var string $collapsed */
        $collapsed = (string) preg_replace('/\s+/u', ' ', $trimmed);
        $nameLower = mb_strtolower($collapsed, 'UTF-8');

        /** @var list<string> $existingNames */
        $existingNames = array_values(array_map(
            static fn ($v): string => (string) $v,
            Role::query()
                ->pluck('name')
                ->filter(static fn ($v): bool => is_string($v))
                ->all()
        ));

        foreach ($existingNames as $ex) {
            if (mb_strtolower($ex, 'UTF-8') === $nameLower) {
                return response()->json([
                    'ok'      => false,
                    'code'    => 'VALIDATION_FAILED',
                    'message' => 'The given data was invalid.',
                    'errors'  => ['name' => ['Role already exists after normalization.']],
                ], 422);
            }
        }

        $base = 'role_' . Str::slug($raw, '_');

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

        // store normalized lowercase
        $role = Role::query()->create(['id' => $id, 'name' => $nameLower]);

        $this->logRoleAudit($request, 'rbac.role.created', $role, [
            'role'            => $collapsed,
            'name'            => $collapsed,
            'name_normalized' => $nameLower,
        ]);

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'       => true,
                'note'     => 'stub-only',
                'accepted' => [
                    'role' => $role,
                    'name' => is_string($request->input('name')) ? $request->input('name') : null,
                ],
            ], 202);
        }

        $target = $this->resolveRoleModel($role);
        if (!$target instanceof Role) {
            return response()->json([
                'ok'             => false,
                'code'           => 'ROLE_NOT_FOUND',
                'missing_roles'  => [$role],
            ], 404);
        }

        $validated = $this->validateRoleNameInput($request, $target);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $previousName = $target->name;
        $collapsed     = $validated['collapsed'];
        $normalized    = $validated['normalized'];

        if ($previousName === $normalized) {
            return response()->json([
                'ok'   => true,
                'role' => ['id' => $target->id, 'name' => $target->name],
                'note' => 'unchanged',
            ], 200);
        }

        $target->name = $normalized;
        $target->save();

        $this->logRoleAudit($request, 'rbac.role.updated', $target, [
            'role'             => $collapsed,
            'name'             => $collapsed,
            'name_normalized'  => $normalized,
            'name_previous'    => $previousName,
        ]);

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $target->id, 'name' => $target->name],
        ], 200);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'   => true,
                'note' => 'stub-only',
            ], 202);
        }

        $target = $this->resolveRoleModel($role);
        if (!$target instanceof Role) {
            return response()->json([
                'ok'             => false,
                'code'           => 'ROLE_NOT_FOUND',
                'missing_roles'  => [$role],
            ], 404);
        }

        $meta = [
            'role'            => $target->name,
            'name'            => $target->name,
            'role_id'         => $target->id,
            'name_normalized' => $target->name,
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
     * @param array<string, list<string>> $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => 'VALIDATION_FAILED',
            'message' => 'The given data was invalid.',
            'errors'  => $errors,
        ], 422);
    }

    /**
     * @return array{collapsed:string, normalized:string}|JsonResponse
     */
    private function validateRoleNameInput(Request $request, ?Role $ignore = null): array|JsonResponse
    {
        /** @var mixed $raw */
        $raw = $request->input('name');
        if (!is_string($raw)) {
            return $this->validationFailed(['name' => ['Role name is required.']]);
        }

        /** @var string $collapsed */
        $collapsed = (string) preg_replace('/\s+/u', ' ', trim($raw));

        $validator = Validator::make(
            ['name' => $collapsed],
            [
                'name' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[\p{L}\p{N}_-]{2,64}$/u'],
            ],
            [
                'name.required' => 'Role name is required.',
                'name.string'   => 'Role name must be a string.',
                'name.min'      => 'Role name must be at least 2 characters.',
                'name.max'      => 'Role name must be at most 64 characters.',
                'name.regex'    => 'Role name may contain only letters, numbers, underscores, and hyphens.',
            ]
        );

        if ($validator->fails()) {
            /** @var array<string,list<string>> $errors */
            $errors = $validator->errors()->toArray();
            return $this->validationFailed($errors);
        }

        $normalized = mb_strtolower($collapsed, 'UTF-8');

        $query = Role::query()->whereRaw('LOWER(name) = ?', [$normalized]);
        if ($ignore instanceof Role) {
            /** @var string|null $attr */
            $attr = $ignore->getAttribute('id');
            if (is_string($attr) && $attr !== '') {
                $query->where('id', '!=', $attr);
            }
        }

        $exists = $query->exists();

        if ($exists) {
            return $this->validationFailed(['name' => ['Role already exists after normalization.']]);
        }

        return ['collapsed' => $collapsed, 'normalized' => $normalized];
    }

    private function normalizeRoleName(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
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

            if (!is_string($idAttr) || !is_string($nameAttr)) {
                continue;
            }

            if ($this->canonicalRoleKey($idAttr) === $canonical || $this->canonicalRoleKey($nameAttr) === $canonical) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param non-empty-string $action
     * @param array<string, mixed> $meta
     */
    private function logRoleAudit(Request $request, string $action, Role $role, array $meta = []): void
    {
        try {
            /** @var AuditLogger $logger */
            $logger  = app(AuditLogger::class);
            $actor   = Auth::user();
            $actorId = Auth::id();

            $actorName  = ($actor instanceof User) ? trim($actor->name) : '';
            $actorEmail = ($actor instanceof User) ? trim($actor->email) : '';

            $metaWithActor = $meta;
            if ($actorName !== '' && !array_key_exists('actor_username', $metaWithActor)) {
                $metaWithActor['actor_username'] = $actorName;
            }
            if ($actorEmail !== '' && !array_key_exists('actor_email', $metaWithActor)) {
                $metaWithActor['actor_email'] = $actorEmail;
            }

            $roleId = $role->getAttribute('id');
            if (!is_string($roleId) || $roleId === '') {
                throw new \LogicException('Role id must be a non-empty string.');
            }

            $logger->log([
                'actor_id'    => is_int($actorId) ? $actorId : null,
                'action'      => $action,
                'category'    => 'RBAC',
                'entity_type' => 'role',
                'entity_id'   => $this->nes($roleId),
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => $metaWithActor,
            ]);
        } catch (\Throwable) {
            // ignore audit errors
        }
    }
}

