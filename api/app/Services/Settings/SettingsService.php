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
        'rbac.enabled',
        'rbac.roles',
        'audit.enabled',
        'audit.retention_days',
        'evidence.enabled',
        'evidence.max_mb',
        'evidence.allowed_mime',
        'avatars.enabled',
        'avatars.size_px',
        'avatars.format',
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

        /** @var string $dotKey */
        /** @var mixed  $val */
        foreach ($overrides as $dotKey => $val) {
            if (!str_starts_with($dotKey, 'core.')) {
                continue;
            }
            /** @var string $sub */
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
     * @param array<string,mixed> $accepted
     * @param int|null $actorId
     * @param array<string,mixed> $context
     * @return array{changes: array<int, array{key:string, old:mixed, new:mixed, action:string}>}
     */
    public function apply(array $accepted, ?int $actorId = null, array $context = []): array
    {
        /** @var array<string, mixed> $onlyAccepted */
        $onlyAccepted = Arr::only($accepted, ['rbac', 'audit', 'evidence', 'avatars']);

        /** @var array<string, mixed> $flatAcceptedInput */
        $flatAcceptedInput = Arr::dot($onlyAccepted);

        /** @var array<string, mixed> $flatAccepted */
        $flatAccepted = $this->prefixWithCore($flatAcceptedInput);

        /** @var array<string, mixed> $coreCfg */
        $coreCfg = (array) config('core', []);
        /** @var array<string, mixed> $baseFiltered */
        $baseFiltered = $this->filterForContract($coreCfg);
        /** @var array<string, mixed> $baseFlat */
        $baseFlat = Arr::dot($baseFiltered);
        /** @var array<string, mixed> $baseline */
        $baseline = $this->prefixWithCore($baseFlat);

        /** @var array<string, mixed> $current */
        $current = $this->currentOverrides();

        /** @var array<string, mixed> $desired */
        $desired = [];
        /** @var string $k0 */
        /** @var mixed $v0 */
        foreach ($current as $k0 => $v0) {
            /** @psalm-suppress MixedAssignment */
            $desired[$k0] = $v0;
        }

        /** @var string $key */
        /** @var mixed  $val */
        foreach ($flatAccepted as $key => $val) {
            /** @var mixed $default */
            $default = $baseline[$key] ?? null;

            if ($this->valuesEqual($val, $default)) {
                if (array_key_exists($key, $desired)) {
                    unset($desired[$key]);
                }
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $desired[$key] = $val;
        }

        /** @var list<string> $touchedKeys */
        $touchedKeys = array_keys($flatAccepted);

        /** @var list<string> $becameUnset */
        $becameUnset = array_values(array_diff(array_keys($current), array_keys($desired)));

        /** @var string $k */
        foreach ($becameUnset as $k) {
            if (!in_array($k, $touchedKeys, true)) {
                $touchedKeys[] = $k;
            }
        }

        if ($this->persistenceAvailable()) {
            /** @var list<string> $toDelete */
            $toDelete = array_values(array_intersect($becameUnset, $touchedKeys));

            /** @var array<string, mixed> $toUpsert */
            $toUpsert = [];
            /** @var string $k */
            foreach ($touchedKeys as $k) {
                /** @var mixed $cur */
                $cur = $current[$k] ?? null;
                /** @var mixed $des */
                $des = $desired[$k] ?? null;
                if ($des === null) {
                    continue;
                }
                if ($cur === null || !$this->valuesEqual($cur, $des)) {
                    /** @psalm-suppress MixedAssignment */
                    $toUpsert[$k] = $des;
                }
            }

            DB::transaction(function () use ($toDelete, $toUpsert, $actorId): void {
                if ($toDelete !== []) {
                    Setting::query()->whereIn('key', $toDelete)->delete();
                }
                if ($toUpsert !== []) {
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
                    /** @var string $k */
                    /** @var mixed  $v */
                    foreach ($toUpsert as $k => $v) {
                        $rows[] = [
                            'key'        => $k,
                            'value'      => $this->encodeJson($v),
                            'type'       => 'json',
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
                }
            });
        }

        /** @var list<array{key:string, old:mixed, new:mixed, action:string}> $changes */
        $changes = [];
        /** @var string $k */
        foreach ($touchedKeys as $k) {
            /** @var mixed $old */
            $old = $current[$k] ?? null;
            /** @var mixed $new */
            $new = $desired[$k] ?? null;

            if ($old === null && $new === null) {
                continue;
            }

            /** @var 'unset'|'set'|'update' $action */
            $action = $new === null ? 'unset' : ($old === null ? 'set' : 'update');
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
        $rows = Setting::query()->select(['key', 'value'])->get();

        /** @var array<string, mixed> $out */
        $out = [];
        /** @var Setting $row */
        foreach ($rows as $row) {
            /** @var mixed $keyAttrRaw */
            $keyAttrRaw = $row->getAttribute('key');
            if (!is_string($keyAttrRaw)) {
                continue;
            }
            $keyAttr = $keyAttrRaw;

            /** @var mixed $valueRaw */
            $valueRaw = $row->getAttribute('value');
            if (!is_string($valueRaw)) {
                // Skip invalid non-string payloads defensively.
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($valueRaw, true);

            /** @psalm-suppress MixedAssignment */
            $out[$keyAttr] = $decoded;
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
        /** @var string $k */
        /** @var mixed  $v */
        foreach ($flat as $k => $v) {
            if (
                str_starts_with($k, 'rbac.') ||
                str_starts_with($k, 'audit.') ||
                str_starts_with($k, 'evidence.') ||
                str_starts_with($k, 'avatars.')
            ) {
                /** @psalm-suppress MixedAssignment */
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
        /** @var array<int|string, mixed> $arr */
        $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
        if ($isAssoc) {
            ksort($arr);
            /** @var int|string $k */
            /** @var mixed $v */
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
        /** @var mixed $v */
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

    private function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON for settings value.');
        }
        return $json;
    }

    /**
     * @param array<string,mixed> $core
     * @return array<string,mixed>
     */
    private function filterForContract(array $core): array
    {
        /** @var array<string,mixed> $coreOnly */
        $coreOnly = Arr::only($core, ['rbac', 'audit', 'evidence', 'avatars']);

        /** @var array<string, mixed> $rbac */
        $rbac = Arr::only((array) ($coreOnly['rbac'] ?? []), ['enabled', 'roles']);
        /** @var array<string, mixed> $audit */
        $audit = Arr::only((array) ($coreOnly['audit'] ?? []), ['enabled', 'retention_days']);
        /** @var array<string, mixed> $evidence */
        $evidence = Arr::only((array) ($coreOnly['evidence'] ?? []), ['enabled', 'max_mb', 'allowed_mime']);
        /** @var array<string, mixed> $avatars */
        $avatars = Arr::only((array) ($coreOnly['avatars'] ?? []), ['enabled', 'size_px', 'format']);

        return [
            'rbac'     => $rbac,
            'audit'    => $audit,
            'evidence' => $evidence,
            'avatars'  => $avatars,
        ];
    }

    private function isContractKey(string $subKey): bool
    {
        return in_array($subKey, self::CONTRACT_KEYS, true);
    }
}

