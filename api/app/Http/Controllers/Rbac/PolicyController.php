<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route as Router;

final class PolicyController extends Controller
{
    /** @var array<string, true> */
    private static array $unknownRoleAuditOnce = [];

    public function show(Request $request): JsonResponse
    {
        [$mode, $persistence, $defaults, $overrides, $effective] = $this->compute();

        $nowIso = CarbonImmutable::now('UTC')->toIso8601String();

        /** @var list<string> $allPolicies */
        $allPolicies = \array_keys($effective);
        \sort($allPolicies);

        /** @var list<string> $allRoles */
        $allRoles = [];
        foreach ($effective as $roles) {
            $allRoles = \array_merge($allRoles, $roles);
        }
        $allRoles = \array_values(\array_unique($allRoles));
        \sort($allRoles);

        $fingerprint = self::fingerprint($mode, $persistence, $effective, $allRoles);

        return response()->json([
            'ok' => true,
            // Legacy fields
            'mode' => $mode,
            'persistence' => $persistence,
            'defaults' => $defaults,
            'overrides' => $overrides,
            'effective' => $effective,
            // Normalized view
            'data' => [
                'policies' => $effective,
                'catalog' => [
                    'policies' => $allPolicies,
                    'roles' => $allRoles,
                ],
            ],
            'meta' => [
                'mode' => $mode,
                'persistence' => $persistence,
                'counts' => [
                    'defaults' => \count($defaults),
                    'overrides' => \count($overrides),
                    'effective' => \count($effective),
                ],
                'catalog' => [
                    'policies' => $allPolicies,
                    'roles' => $allRoles,
                ],
                'fingerprint' => $fingerprint,
                'generated_at' => $nowIso,
            ],
            'generated_at' => $nowIso,
            'ts' => $nowIso,
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function effective(Request $request): JsonResponse
    {
        [$mode, $persistence, $defaults, $overrides, $effective] = $this->compute();

        $nowIso = CarbonImmutable::now('UTC')->toIso8601String();

        /** @var list<string> $allPolicies */
        $allPolicies = \array_keys($effective);
        \sort($allPolicies);

        /** @var list<string> $allRoles */
        $allRoles = [];
        foreach ($effective as $roles) {
            $allRoles = \array_merge($allRoles, $roles);
        }
        $allRoles = \array_values(\array_unique($allRoles));
        \sort($allRoles);

        $fingerprint = self::fingerprint($mode, $persistence, $effective, $allRoles);

        return response()->json([
            'ok' => true,
            'data' => [
                'policies' => $effective,
                'catalog' => [
                    'policies' => $allPolicies,
                    'roles' => $allRoles,
                ],
            ],
            'meta' => [
                'mode' => $mode,
                'persistence' => $persistence,
                'counts' => [
                    'defaults' => \count($defaults),
                    'overrides' => \count($overrides),
                    'effective' => \count($effective),
                ],
                'catalog' => [
                    'policies' => $allPolicies,
                    'roles' => $allRoles,
                ],
                'fingerprint' => $fingerprint,
                'generated_at' => $nowIso,
            ],
            'generated_at' => $nowIso,
            'ts' => $nowIso,
        ], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * @return array{
     *   0:string,
     *   1:string,
     *   2:array<string,list<string>>,
     *   3:array<string,list<string>>,
     *   4:array<string,list<string>>
     * }
     *
     * @psalm-return array{
     *   0:string,
     *   1:string,
     *   2:array<string,list<string>>,
     *   3:array<string,list<string>>,
     *   4:array<string,list<string>>
     * }
     */
    private function compute(): array
    {
        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        /** @var mixed $persistVal */
        $persistVal = config('core.rbac.persistence');

        $mode = \is_string($modeVal) && \in_array($modeVal, ['stub', 'persist'], true) ? $modeVal : 'persist';
        $persistence = \is_string($persistVal) ? $persistVal : '';

        /** @var array<string,list<string>> $baseline */
        $baseline = [
            'core.settings.manage' => ['admin'],
            'core.audit.view' => ['admin', 'auditor'],
            'core.audit.export' => ['admin'],
            'core.metrics.view' => ['admin'],
            'core.users.view' => ['admin'],
            'core.users.manage' => ['admin'],
            'core.evidence.view' => ['admin', 'auditor'],
            'core.evidence.manage' => ['admin'],
            'core.exports.generate' => ['admin'],
            'core.rbac.view' => ['admin'],
            'rbac.roles.manage' => ['admin'],
            'rbac.user_roles.manage' => ['admin'],
        ];

        /** @var array<string,mixed> $cfgDefaultsRaw */
        $cfgDefaultsRaw = (array) config('core.rbac.policies.defaults', []);
        /** @var array<string,mixed> $cfgOverridesRaw */
        $cfgOverridesRaw = (array) config('core.rbac.policies.overrides', []);
        /** @var array<string,mixed> $cfgTopRaw */
        $cfgTopRaw = (array) config('core.rbac.policies', []);

        // Back-compat: if both buckets empty but top-level exists, treat it as defaults
        if ($cfgDefaultsRaw === [] && $cfgOverridesRaw === [] && $cfgTopRaw !== []) {
            $cfgDefaultsRaw = $cfgTopRaw;
        }

        $defaults = self::normalizePolicies(self::mergePolicies($baseline, $cfgDefaultsRaw));
        $overrides = self::normalizePolicies($cfgOverridesRaw);

        // Persist-mode only: emit audits for unknown roles in overrides once per policy per boot.
        $this->emitUnknownRoleAudits($mode, $overrides);

        /** @var RouteCollectionInterface $collection */
        $collection = Router::getRoutes();
        /** @var array<int,Route> $allRoutes */
        $allRoutes = $collection->getRoutes();

        /** @var array<string,list<string>> $fromRoutes */
        $fromRoutes = [];
        foreach ($allRoutes as $route) {
            /** @var array<string,mixed> $action */
            $action = $route->getAction();

            /** @var string|null $policy */
            $policy = null;
            if (isset($action['policy']) && \is_string($action['policy']) && $action['policy'] !== '') {
                $policy = $action['policy'];
            } elseif (isset($action['defaults']) && \is_array($action['defaults'])) {
                /** @var mixed $p2 */
                $p2 = $action['defaults']['policy'] ?? null;
                if (\is_string($p2) && $p2 !== '') {
                    $policy = $p2;
                }
            }
            if ($policy === null) {
                continue;
            }

            /** @var list<string> $roles */
            $roles = self::toStringList(
                \array_key_exists('roles', $action)
                    ? $action['roles']
                    : ((isset($action['defaults']) && \is_array($action['defaults']) && \array_key_exists('roles', $action['defaults']))
                        ? $action['defaults']['roles']
                        : null)
            );

            if (! isset($fromRoutes[$policy])) {
                $fromRoutes[$policy] = [];
            }
            /** @var list<string> $existing */
            $existing = $fromRoutes[$policy];
            $fromRoutes[$policy] = \array_values(\array_unique(\array_merge($existing, $roles)));
        }
        $fromRoutes = self::normalizePolicies($fromRoutes);

        $effective = self::normalizePolicies(
            self::mergePolicies(self::mergePolicies($defaults, $overrides), $fromRoutes)
        );

        return [$mode, $persistence, $defaults, $overrides, $effective];
    }

    /**
     * Emit once-per-policy-per-boot audits for unknown roles present in overrides.
     *
     * @param  array<string, list<string>>  $overrides
     */
    private function emitUnknownRoleAudits(string $mode, array $overrides): void
    {
        if ($mode !== 'persist') {
            return;
        }
        if (! (bool) config('core.audit.enabled', true)) {
            return;
        }

        /** @var list<string> $roleCatalogRaw */
        $roleCatalogRaw = (array) config('core.rbac.roles', []);
        /** @var list<non-empty-string> $roleCatalog */
        $roleCatalog = self::toStringList($roleCatalogRaw);

        /** @var array<string, true> $known */
        $known = [];
        foreach ($roleCatalog as $r) {
            $known[$r] = true;
        }

        if ($overrides === []) {
            return;
        }

        /** @var AuditLogger $logger */
        $logger = app(AuditLogger::class);

        foreach ($overrides as $policy => $roles) {
            if ($policy === '') {
                continue;
            }
            /** @var non-empty-string $policyKey */
            $policyKey = $policy;

            /** @var list<non-empty-string> $unknown */
            $unknown = [];
            foreach ($roles as $r) {
                if ($r === '') {
                    continue;
                }
                if (! isset($known[$r])) {
                    $unknown[] = $r;
                }
            }
            if ($unknown === []) {
                continue;
            }
            if (isset(self::$unknownRoleAuditOnce[$policyKey])) {
                continue;
            }
            self::$unknownRoleAuditOnce[$policyKey] = true;

            $logger->log([
                'action' => 'rbac.policy.override.unknown_role',
                'category' => 'RBAC',
                'entity_type' => 'policy',
                'entity_id' => $policyKey,
                'meta' => [
                    'policy' => $policyKey,
                    'unknown_roles' => \array_values(\array_unique($unknown)),
                    'source' => 'overrides',
                ],
            ]);
        }
    }

    /**
     * @param  array<array-key,mixed>  $a
     * @param  array<array-key,mixed>  $b
     * @return array<string,list<string>>
     *
     * @psalm-return array<string,list<string>>
     */
    private static function mergePolicies(array $a, array $b): array
    {
        $out = self::normalizePolicies($a);
        $b = self::normalizePolicies($b);

        foreach ($b as $key => $roles) {
            /** @var string $key */
            if (! isset($out[$key])) {
                $out[$key] = $roles;

                continue;
            }
            $out[$key] = \array_values(\array_unique(\array_merge($out[$key], $roles)));
        }

        return $out;
    }

    /**
     * @param  array<array-key,mixed>  $map
     * @return array<string,list<string>>
     *
     * @psalm-return array<string,list<string>>
     */
    private static function normalizePolicies(array $map): array
    {
        /** @var array<string,list<string>> $norm */
        $norm = [];

        foreach (\array_keys($map) as $k) {
            if (! \is_string($k) || $k === '') {
                continue;
            }

            /** @var mixed $raw */
            $raw = $map[$k];

            /** @var list<non-empty-string> $roles */
            $roles = self::toStringList($raw);
            $norm[$k] = \array_values(\array_unique($roles));
        }

        return $norm;
    }

    /**
     * @return list<non-empty-string>
     *
     * @psalm-return list<non-empty-string>
     */
    private static function toStringList(mixed $input): array
    {
        // Single string fast-path.
        if (\is_string($input) && $input !== '') {
            /** @var list<non-empty-string> $one */
            $one = [\mb_strtolower($input)];

            return $one;
        }

        if (! \is_array($input)) {
            return [];
        }

        /** @var array<int,mixed> $arr */
        $arr = $input;

        // Keep only non-empty strings.
        /** @var list<string> $filtered */
        $filtered = \array_values(\array_filter(
            $arr,
            static fn ($v): bool => \is_string($v) && $v !== ''
        ));

        // Lowercase.
        /** @var list<string> $lower */
        $lower = \array_map(
            static fn (string $s): string => \mb_strtolower($s),
            $filtered
        );

        // Dedup and reindex. Result elements are still non-empty.
        /** @var list<non-empty-string> $dedup */
        $dedup = \array_values(\array_unique($lower));

        return $dedup;
    }

    /**
     * @param  array<string,list<string>>  $effective
     * @param  list<string>  $allRoles
     */
    private static function fingerprint(string $mode, string $persistence, array $effective, array $allRoles): string
    {
        $payload = \json_encode(
            ['mode' => $mode, 'persistence' => $persistence, 'policies' => $effective, 'roles' => $allRoles],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            $payload = (string) \microtime(true);
        }

        return \sha1($payload);
    }
}
