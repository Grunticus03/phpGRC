<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PolicyDefinitions;
use App\Support\Rbac\PolicyMap;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RolePoliciesController extends Controller
{
    public function index(): JsonResponse
    {
        $modeData = $this->resolveMode();
        $mode = $modeData['mode'];
        $persistence = $modeData['persistence'];

        /** @var array<string, list<string>> $effective */
        $effective = PolicyMap::effective();

        /** @var array<string, array{label:?string, description:?string}> $defined */
        $defined = PolicyDefinitions::definitions();

        /** @var array<string, string|null> $labelsFromDb */
        $labelsFromDb = $this->lookupLabelsFromDatabase();

        /** @var array<string, true> $policyKeys */
        $policyKeys = [];
        foreach (array_keys($defined) as $key) {
            if ($key !== '') {
                $policyKeys[$key] = true;
            }
        }
        foreach (array_keys($effective) as $key) {
            if ($key !== '') {
                $policyKeys[$key] = true;
            }
        }

        /** @var list<string> $orderedPolicies */
        $orderedPolicies = array_keys($policyKeys);
        sort($orderedPolicies, SORT_STRING);

        /** @var list<array<string,mixed>> $policies */
        $policies = [];
        foreach ($orderedPolicies as $policy) {
            /** @var list<string> $rolesRaw */
            $rolesRaw = $effective[$policy] ?? [];
            $roles = array_map(
                fn (string $token): string => $this->displayRoleToken($token),
                $rolesRaw
            );

            $definition = $defined[$policy] ?? ['label' => null, 'description' => null];
            $definitionLabel = $definition['label'] ?? null;
            $label = $labelsFromDb[$policy] ?? $definitionLabel;

            $policies[] = [
                'policy' => $policy,
                'label' => $label,
                'description' => $definition['description'] ?? null,
                'roles' => array_values(array_unique($roles)),
            ];
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'policies' => $policies,
            ],
            'meta' => [
                'mode' => $mode,
                'persistence' => $persistence,
                'policy_count' => count($policies),
                'role_catalog' => PolicyMap::roleCatalog(),
            ],
        ]);
    }

    public function show(Request $request, string $role): JsonResponse
    {
        $resolved = $this->resolveRole($role);
        if ($resolved === null) {
            return response()->json([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => [$role],
            ], 404);
        }

        $modeData = $this->resolveMode();
        $mode = $modeData['mode'];
        $persistence = $modeData['persistence'];

        /** @var array<string,list<string>> $effective */
        $effective = PolicyMap::effective();

        /** @var list<string> $tokens */
        $tokens = $this->roleTokens($resolved);
        /** @var list<string> $policies */
        $policies = [];

        foreach ($effective as $policy => $roles) {
            foreach ($tokens as $token) {
                if (in_array($token, $roles, true)) {
                    $policies[] = $policy;
                    break;
                }
            }
        }

        sort($policies, SORT_STRING);

        return response()->json([
            'ok' => true,
            'role' => $resolved,
            'policies' => $policies,
            'meta' => [
                'assignable' => $this->persistenceEnabled() && $this->policyTablesAvailable(),
                'mode' => $mode,
                'persistence' => $persistence,
            ],
        ]);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        if (! $this->persistenceEnabled() || ! $this->policyTablesAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
            ], 202);
        }

        $resolved = $this->resolveRole($role, true);
        if ($resolved === null) {
            return response()->json([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => [$role],
            ], 404);
        }

        /** @var array{policies:array<int, string>} $payload */
        $payload = $request->validate([
            'policies' => ['present', 'array'],
            'policies.*' => ['string', 'min:3', 'max:128'],
        ]);

        /** @var array<int, string> $input */
        $input = $payload['policies'];
        /** @var list<string> $policies */
        $policies = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($input as $key) {
            $trimmed = trim($key);
            if ($trimmed === '') {
                return $this->validationFailed(['policies' => ['Policy keys must be non-empty strings.']]);
            }
            if (isset($seen[$trimmed])) {
                return $this->validationFailed(['policies' => ['Duplicate policy keys are not allowed.']]);
            }
            $seen[$trimmed] = true;
            $policies[] = $trimmed;
        }

        $available = $this->availablePolicies();
        /** @var list<string> $unknown */
        $unknown = array_values(array_diff($policies, $available));
        if ($unknown !== []) {
            return $this->validationFailed(['policies' => [sprintf('Unknown policies: %s', implode(', ', $unknown))]]);
        }

        /** @var string $roleId */
        $roleId = $resolved['id'];
        /** @var list<string> $before */
        $before = DB::table('policy_role_assignments')
            ->where('role_id', $roleId)
            ->pluck('policy')
            ->sort()
            ->values()
            ->all();

        $now = CarbonImmutable::now('UTC')->toDateTimeString();

        DB::transaction(function () use ($policies, $roleId, $now): void {
            DB::table('policy_role_assignments')
                ->where('role_id', $roleId)
                ->delete();

            if ($policies === []) {
                return;
            }

            /** @var list<array<string,string>> $rows */
            $rows = [];
            foreach ($policies as $policy) {
                $rows[] = [
                    'policy' => $policy,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('policy_role_assignments')->insert($rows);
        });

        PolicyMap::clearCache();

        /** @var list<string> $after */
        $after = DB::table('policy_role_assignments')
            ->where('role_id', $roleId)
            ->pluck('policy')
            ->sort()
            ->values()
            ->all();

        $this->writeAudit($request, $resolved, $before, $after);

        return response()->json([
            'ok' => true,
            'role' => $resolved,
            'policies' => $after,
        ]);
    }

    /**
     * @return array{mode:string,persistence:string|null}
     */
    private function resolveMode(): array
    {
        /** @var mixed $modeRaw */
        $modeRaw = config('core.rbac.mode');
        $modeLower = is_string($modeRaw) ? strtolower($modeRaw) : 'stub';
        if (! in_array($modeLower, ['stub', 'persist', 'db'], true)) {
            $modeLower = 'stub';
        }

        /** @var mixed $persistRaw */
        $persistRaw = config('core.rbac.persistence');
        $persistFlag = filter_var($persistRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        $effectiveMode = $modeLower === 'db' ? 'persist' : $modeLower;
        if ($persistFlag === true) {
            $effectiveMode = 'persist';
        }

        $persistence = null;
        if (is_bool($persistRaw)) {
            $persistence = $persistRaw ? 'true' : 'false';
        } elseif (is_string($persistRaw) && trim($persistRaw) !== '') {
            $persistence = $persistRaw;
        }

        return ['mode' => $effectiveMode, 'persistence' => $persistence];
    }

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

    private function policyTablesAvailable(): bool
    {
        return Schema::hasTable('policy_role_assignments') && Schema::hasTable('policy_roles');
    }

    /**
     * @return array<string, string|null>
     */
    private function lookupLabelsFromDatabase(): array
    {
        if (! Schema::hasTable('policy_roles')) {
            return [];
        }

        try {
            /** @var \Illuminate\Support\Collection<int, object> $rows */
            $rows = DB::table('policy_roles')
                ->select(['policy', 'label'])
                ->get();
        } catch (\Throwable) {
            return [];
        }

        /** @var array<string, string|null> $map */
        $map = [];
        foreach ($rows as $row) {
            $policy = is_string($row->policy ?? null) ? trim((string) $row->policy) : '';
            if ($policy === '') {
                continue;
            }

            /** @var mixed $labelRaw */
            $labelRaw = $row->label ?? null;
            $label = is_string($labelRaw) ? trim($labelRaw) : null;
            $map[$policy] = $label === '' ? null : $label;
        }

        return $map;
    }

    /**
     * @param  array{id?:string|null,key:string,label:string,name?:string|null}  $resolved
     * @return list<string>
     */
    private function roleTokens(array $resolved): array
    {
        $tokens = [];
        $key = $resolved['key'];
        if ($key !== '') {
            $tokens[] = $key;
            $tokens[] = 'role_'.$key;
        }

        $id = $resolved['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $tokens[] = $id;
        }

        if (array_key_exists('name', $resolved)) {
            $nameValue = $resolved['name'];
            if (is_string($nameValue) && $nameValue !== '') {
                $tokens[] = $this->canonicalRoleKey($nameValue);
            }
        }

        /** @var list<string> $out */
        $out = array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));

        return $out;
    }

    private function displayRoleToken(string $token): string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'role_')) {
            $withoutPrefix = substr($trimmed, 5);

            return $withoutPrefix !== '' ? $withoutPrefix : $trimmed;
        }

        return $trimmed;
    }

    /**
     * @return array{id:string,key:string,label:string,name?:string}|null
     */
    private function resolveRole(string $value, bool $requirePersisted = false): ?array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $canonical = $this->canonicalRoleKey($trimmed);
        if ($canonical === '') {
            return null;
        }

        if ($this->persistenceEnabled() && Schema::hasTable('roles')) {
            /** @var Role|null $model */
            $model = $this->findRoleModel($trimmed);
            if ($model instanceof Role) {
                $idRaw = $model->getAttribute('id');
                $nameRaw = $model->getAttribute('name');
                if (! is_string($idRaw) || $idRaw === '' || ! is_string($nameRaw) || $nameRaw === '') {
                    return null;
                }
                $id = $idRaw;
                $name = $nameRaw;

                return [
                    'id' => $id,
                    'key' => $this->canonicalRoleKey($name),
                    'name' => $name,
                    'label' => $this->humanizeRole($name),
                ];
            }

            if ($requirePersisted) {
                return null;
            }
        } elseif ($requirePersisted) {
            return null;
        }

        /** @var list<string> $catalog */
        $catalog = PolicyMap::roleCatalog();
        if (! in_array($canonical, $catalog, true) && ! in_array('role_'.$canonical, $catalog, true)) {
            return null;
        }

        return [
            'id' => 'role_'.$canonical,
            'key' => $canonical,
            'name' => $canonical,
            'label' => $this->humanizeRole($canonical),
        ];
    }

    private function findRoleModel(string $value): ?Role
    {
        $variants = $this->roleLookupVariants($value);
        foreach ($variants as $variant) {
            /** @var Role|null $found */
            $found = Role::query()->whereKey($variant)->first();
            if ($found instanceof Role) {
                return $found;
            }
        }

        $normalized = $this->normalizeRoleName($value);
        /** @var Role|null $byName */
        $byName = Role::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized, 'UTF-8')])
            ->first();

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
     * @return list<string>
     */
    private function roleLookupVariants(string $value): array
    {
        $variants = [$value];
        $normalized = $this->normalizeRoleName($value);
        if ($normalized !== $value) {
            $variants[] = $normalized;
        }
        $canonical = $this->canonicalRoleKey($value);
        if ($canonical !== '') {
            $variants[] = $canonical;
            $variants[] = 'role_'.$canonical;
        }

        /** @var list<string> $out */
        $out = array_values(array_unique(array_filter($variants, static fn (string $v): bool => trim($v) !== '')));

        return $out;
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

    private function humanizeRole(string $canonical): string
    {
        if ($canonical === '') {
            return '';
        }

        $withSpaces = str_replace('_', ' ', $canonical);

        return ucwords($withSpaces);
    }

    /**
     * @return list<string>
     */
    private function availablePolicies(): array
    {
        /** @var list<string> $keys */
        $keys = PolicyMap::policyKeys();

        if (Schema::hasTable('policy_roles')) {
            try {
                /** @var list<string> $dbPolicies */
                $dbPolicies = DB::table('policy_roles')
                    ->pluck('policy')
                    ->filter(static fn ($v): bool => is_string($v) && trim($v) !== '')
                    ->map(static fn ($v): string => trim((string) $v))
                    ->values()
                    ->all();
                $keys = array_merge($keys, $dbPolicies);
            } catch (\Throwable) {
                // ignore DB failure and fall back to PolicyMap keys
            }
        }

        /** @var list<string> $defined */
        $defined = array_keys(PolicyDefinitions::definitions());
        $keys = array_merge($keys, $defined);

        $unique = [];
        foreach ($keys as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || in_array($candidate, $unique, true)) {
                continue;
            }
            $unique[] = $candidate;
        }

        return $unique;
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
     * @param  array<string,mixed>  $role
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function writeAudit(Request $request, array $role, array $before, array $after): void
    {
        if (! (bool) config('core.audit.enabled', true) || ! Schema::hasTable('audit_events')) {
            return;
        }

        $id = $role['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return;
        }

        try {
            /** @var AuditLogger $logger */
            $logger = app(AuditLogger::class);
            /** @var User|null $actor */
            $actor = Auth::user();
            $actorId = Auth::id();

            $roleKey = null;
            if (array_key_exists('key', $role) && is_string($role['key']) && $role['key'] !== '') {
                $roleKey = $role['key'];
            }

            /** @var array<string,mixed> $meta */
            $meta = [
                'role_id' => $id,
                'role_key' => $roleKey,
                'role_label' => $role['label'] ?? null,
                'before' => $before,
                'after' => $after,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ];

            $actorName = ($actor instanceof User) ? trim($actor->name) : '';
            $actorEmail = ($actor instanceof User) ? trim($actor->email) : '';

            if ($actorName !== '') {
                $meta['actor_username'] = $actorName;
            }
            if ($actorEmail !== '') {
                $meta['actor_email'] = $actorEmail;
            }

            $logger->log([
                'actor_id' => is_int($actorId) ? $actorId : null,
                'action' => 'rbac.role.policies.updated',
                'category' => 'RBAC',
                'entity_type' => 'role',
                'entity_id' => $id,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $meta,
            ]);
        } catch (\Throwable) {
            // ignore audit failures
        }
    }
}
