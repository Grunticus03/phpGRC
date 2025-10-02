<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Events\SettingsUpdated;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SettingsService
{
    /** @var list<string> */
    private const CONTRACT_KEYS = [
        // RBAC
        'rbac.enabled',
        'rbac.roles',
        'rbac.require_auth',
        'rbac.user_search.default_per_page',
        // Audit
        'audit.enabled',
        'audit.retention_days',
        // Evidence
        'evidence.enabled',
        'evidence.max_mb',
        'evidence.allowed_mime',
        // Avatars
        'avatars.enabled',
        'avatars.size_px',
        'avatars.format',
        // Metrics (DB-backed knobs)
        'metrics.cache_ttl_seconds',
        'metrics.evidence_freshness.days',
        'metrics.rbac_denies.window_days',
    ];

    /** @return array{core: array<string, mixed>} */
    public function effectiveConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('core', []);
        /** @var array<string, mixed> $overrides */
        $overrides = $this->currentOverrides();

        /** @var array<string, mixed> $merged */
        $merged = $this->filterForContract($defaults);

        /** @psalm-suppress MixedAssignment */
        foreach ($overrides as $dotKey => $val) {
            /** @var string $dotKey */
            if (!str_starts_with($dotKey, 'core.')) {
                continue;
            }
            $sub = substr($dotKey, 5);
            if ($this->isContractKey($sub)) {
                Arr::set($merged, $sub, $val);
            }
        }

        /** @var array<string, mixed> $trimInput */
        $trimInput = [
            'rbac'     => is_array($merged['rbac'] ?? null) ? (array) $merged['rbac'] : [],
            'audit'    => is_array($merged['audit'] ?? null) ? (array) $merged['audit'] : [],
            'evidence' => is_array($merged['evidence'] ?? null) ? (array) $merged['evidence'] : [],
            'avatars'  => is_array($merged['avatars'] ?? null) ? (array) $merged['avatars'] : [],
            'metrics'  => is_array($merged['metrics'] ?? null) ? (array) $merged['metrics'] : [],
        ];

        /** @var array<string, mixed> $finalCore */
        $finalCore = $this->filterForContract($trimInput);

        return ['core' => $finalCore];
    }

    public function persistenceAvailable(): bool
    {
        return Schema::hasTable('core_settings');
    }

    /**
     * Write-only apply: upserts accepted values that changed; never prunes other rows.
     *
     * @param array<string,mixed> $accepted
     * @param int|null            $actorId
     * @param array<string,mixed> $context
     * @return array{changes: array<int, array{key:string, old:mixed, new:mixed, action:string}>}
     */
    public function apply(array $accepted, ?int $actorId = null, array $context = []): array
    {
        /** @var array<string, mixed> $onlyAccepted */
        $onlyAccepted = Arr::only($accepted, ['rbac', 'audit', 'evidence', 'avatars', 'metrics']);

        /** @var array<string, mixed> $flatAcceptedInput */
        $flatAcceptedInput = Arr::dot($onlyAccepted);

        /** @var array<string, mixed> $flatAccepted */
        $flatAccepted = $this->prefixWithCore($flatAcceptedInput);

        /** @var array<string, mixed> $current */
        $current = $this->currentOverrides();

        /** @var list<string> $touchedKeys */
        $touchedKeys = array_keys($flatAccepted);

        /** @var array<string, mixed> $toUpsert */
        $toUpsert = [];
        /** @var string $k */
        /** @var mixed  $v */
        foreach ($flatAccepted as $k => $v) {
            /** @var mixed $cur */
            $cur = array_key_exists($k, $current) ? $current[$k] : null;
            if ($cur === null || !$this->valuesEqual($cur, $v)) {
                /** @psalm-suppress MixedAssignment */
                $toUpsert[$k] = $v;
            }
        }

        if ($this->persistenceAvailable() && $toUpsert !== []) {
            DB::transaction(function () use ($toUpsert, $actorId): void {
                /**
                 * @psalm-var list<array{
                 *   key:string,
                 *   value:string,
                 *   type:string,
                 *   updated_by:int|null,
                 *   updated_at:\Illuminate\Support\Carbon,
                 *   created_at:\Illuminate\Support\Carbon
                 * }> $rows
                 */
                $rows = [];
                $now  = now();
                /** @var string $key */
                /** @var mixed  $val */
                foreach ($toUpsert as $key => $val) {
                    /** @var array{0:string,1:string} $enc */
                    $enc = $this->encodeForStorage($val);
                    $rows[] = [
                        'key'        => $key,
                        'value'      => $enc[0],
                        'type'       => $enc[1],
                        'updated_by' => $actorId,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                }

                Setting::query()->getQuery()->upsert(
                    $rows,
                    ['key'],
                    ['value', 'type', 'updated_by', 'updated_at']
                );
            });
        }

        /** @var list<array{key:string, old:mixed, new:mixed, action:string}> $changes */
        $changes = [];
        foreach ($touchedKeys as $k) {
            /** @var mixed $old */
            $old = array_key_exists($k, $current) ? $current[$k] : null;

            /** @var mixed $new */
            $new = array_key_exists($k, $toUpsert) ? $toUpsert[$k] : $old;

            if ($old === null && $new === null) {
                continue;
            }

            /** @var 'set'|'update' $action */
            $action = $old === null ? 'set' : 'update';

            // If nothing actually changed, skip logging a change.
            if ($action === 'update' && $this->valuesEqual($old, $new)) {
                continue;
            }

            $changes[] = [
                'key'    => $k,
                'old'    => $old,
                'new'    => $new,
                'action' => $action,
            ];
        }

        event(new SettingsUpdated(
            actorId: $actorId,
            changes: $changes,
            context: $context,
            occurredAt: now()
        ));

        return ['changes' => $changes];
    }

    /** @return array<string, mixed> */
    private function currentOverrides(): array
    {
        if (!$this->persistenceAvailable()) {
            return [];
        }

        /** @var Collection<int, Setting> $rows */
        $rows = Setting::query()->select(['key', 'value', 'type'])->get();

        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($rows as $row) {
            /** @var mixed $keyAttrRaw */
            $keyAttrRaw = $row->getAttribute('key');
            /** @var mixed $valRaw */
            $valRaw     = $row->getAttribute('value');
            /** @var mixed $typeAttrRaw */
            $typeAttrRaw = $row->getAttribute('type');

            if (!is_string($keyAttrRaw) || !is_string($valRaw)) {
                continue;
            }

            $type = is_string($typeAttrRaw) ? strtolower($typeAttrRaw) : 'json';

            switch ($type) {
                case 'json': {
                    /** @var mixed $decoded */
                    $decoded = json_decode($valRaw, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        continue 2; // skip invalid JSON rows
                    }
                    /** @psalm-suppress MixedAssignment */
                    $out[$keyAttrRaw] = $decoded;
                    break;
                }
                case 'bool': {
                    $out[$keyAttrRaw] = $this->toBool($valRaw);
                    break;
                }
                case 'int': {
                    $out[$keyAttrRaw] = $this->toInt($valRaw);
                    break;
                }
                case 'float': {
                    $out[$keyAttrRaw] = is_numeric($valRaw) ? (float) $valRaw : 0.0;
                    break;
                }
                default: { // 'string' or unknown
                    $out[$keyAttrRaw] = $valRaw;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    private function prefixWithCore(array $flat): array
    {
        /** @var array<string, mixed> $out */
        $out = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($flat as $k => $v) {
            /** @var string $k */
            if (
                str_starts_with($k, 'rbac.')     ||
                str_starts_with($k, 'audit.')    ||
                str_starts_with($k, 'evidence.') ||
                str_starts_with($k, 'avatars.')  ||
                str_starts_with($k, 'metrics.')
            ) {
                $out['core.' . $k] = $v;
            }
        }
        return $out;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return $this->normalizeArray($a) === $this->normalizeArray($b);
        }
        return $a === $b;
    }

    /**
     * @param array<int|string, mixed> $arr
     * @return array<int|string, mixed>
     */
    private function normalizeArray(array $arr): array
    {
        $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
        if ($isAssoc) {
            ksort($arr);
            /** @psalm-suppress MixedAssignment */
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    /** @var array<int|string, mixed> $nested */
                    $nested = $v;
                    /** @psalm-suppress MixedAssignment */
                    $arr[$k] = $this->normalizeArray($nested);
                }
            }
            return $arr;
        }

        $allScalars = true;
        /** @psalm-suppress MixedAssignment */
        foreach ($arr as $v) {
            if (!is_scalar($v) && $v !== null) {
                $allScalars = false;
                break;
            }
        }
        if ($allScalars) {
            sort($arr);
            return $arr;
        }

        return $arr;
    }

    /**
     * Encode a value for storage, returning [value, type] where value is a string.
     *
     * @return array{0:string,1:string}
     */
    private function encodeForStorage(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value ? '1' : '0', 'bool'];
        }
        if (is_int($value)) {
            return [(string) $value, 'int'];
        }
        if (is_float($value)) {
            // preserve numeric semantics; DB column is text, so stringify
            return [rtrim(rtrim(sprintf('%.14F', $value), '0'), '.'), 'float'];
        }
        if (is_string($value)) {
            return [$value, 'string'];
        }
        // arrays / objects / null => JSON
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON for settings value.');
        }
        return [$json, 'json'];
    }

    /**
     * @param array<string,mixed> $core
     * @return array<string,mixed>
     */
    private function filterForContract(array $core): array
    {
        /** @var array<string,mixed> $coreOnly */
        $coreOnly = Arr::only($core, ['rbac', 'audit', 'evidence', 'avatars', 'metrics']);

        /** @var array<string, mixed> $rbacRaw */
        $rbacRaw = (array) ($coreOnly['rbac'] ?? []);
        /** @var array<string, mixed> $auditRaw */
        $auditRaw = (array) ($coreOnly['audit'] ?? []);
        /** @var array<string, mixed> $evidenceRaw */
        $evidenceRaw = (array) ($coreOnly['evidence'] ?? []);
        /** @var array<string, mixed> $avatarsRaw */
        $avatarsRaw = (array) ($coreOnly['avatars'] ?? []);
        /** @var array<string, mixed> $metricsRaw */
        $metricsRaw = (array) ($coreOnly['metrics'] ?? []);

        /** @var array<string,mixed> $rbac */
        $rbac = [
            'enabled'       => $this->toBool($rbacRaw['enabled'] ?? false),
            'roles'         => $this->toStringList($rbacRaw['roles'] ?? []),
            'require_auth'  => $this->toBool($rbacRaw['require_auth'] ?? false),
            'user_search'   => [
                'default_per_page' => $this->toInt(
                    data_get($rbacRaw, 'user_search.default_per_page', 50)
                ),
            ],
        ];

        /** @var array<string,mixed> $audit */
        $audit = [
            'enabled'        => $this->toBool($auditRaw['enabled'] ?? false),
            'retention_days' => $this->toInt($auditRaw['retention_days'] ?? 0),
        ];

        /** @var array<string,mixed> $evidence */
        $evidence = [
            'enabled'      => $this->toBool($evidenceRaw['enabled'] ?? false),
            'max_mb'       => $this->toInt($evidenceRaw['max_mb'] ?? 0),
            'allowed_mime' => $this->toStringList($evidenceRaw['allowed_mime'] ?? []),
        ];

        /** @var array<string,mixed> $avatars */
        $avatars = [
            'enabled' => $this->toBool($avatarsRaw['enabled'] ?? false),
            'size_px' => $this->toInt($avatarsRaw['size_px'] ?? 0),
            'format'  => $this->toString($avatarsRaw['format'] ?? ''),
        ];

        /** @var array<string, mixed> $efRaw */
        $efRaw = is_array($metricsRaw['evidence_freshness'] ?? null)
            ? (array) $metricsRaw['evidence_freshness']
            : [];
        /** @var array<string, mixed> $rdRaw */
        $rdRaw = is_array($metricsRaw['rbac_denies'] ?? null)
            ? (array) $metricsRaw['rbac_denies']
            : [];

        /** @var array<string,mixed> $metrics */
        $metrics = [
            'cache_ttl_seconds' => $this->toInt($metricsRaw['cache_ttl_seconds'] ?? 0),
            'evidence_freshness' => [
                'days' => $this->toInt($efRaw['days'] ?? 0),
            ],
            'rbac_denies' => [
                'window_days' => $this->toInt($rdRaw['window_days'] ?? 0),
            ],
        ];

        return [
            'rbac'     => $rbac,
            'audit'    => $audit,
            'evidence' => $evidence,
            'avatars'  => $avatars,
            'metrics'  => $metrics,
        ];
    }

    private function isContractKey(string $subKey): bool
    {
        return in_array($subKey, self::CONTRACT_KEYS, true);
    }

    private function toBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        if (is_string($v)) {
            $vv = strtolower(trim($v));
            if ($vv === '1' || $vv === 'true' || $vv === 'on' || $vv === 'yes') return true;
            if ($vv === '0' || $vv === 'false' || $vv === 'off' || $vv === 'no') return false;
            if (ctype_digit($vv)) return (int) $vv === 1;
        }
        return false;
    }

    private function toInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int) $v;
        if (is_string($v) && $v !== '' && preg_match('/^-?\d+$/', $v) === 1) return (int) $v;
        return 0;
    }

    private function toString(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }

    /**
     * @param mixed $v
     * @return list<string>
     */
    private function toStringList(mixed $v): array
    {
        if (!is_array($v)) return [];
        /** @var list<string> $out */
        $out = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($v as $item) {
            if (is_string($item)) {
                $out[] = $item;
            } elseif (is_scalar($item) || $item === null) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }
}

