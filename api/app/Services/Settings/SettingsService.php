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
    /** @return array{core: array<string, mixed>} */
    public function effectiveConfig(): array
    {
        $defaults = (array) config('core', []);
        $overrides = $this->currentOverrides();

        $merged = $defaults;
        foreach ($overrides as $dotKey => $value) {
            if (!str_starts_with($dotKey, 'core.')) {
                continue;
            }
            Arr::set($merged, substr($dotKey, 5), $value);
        }

        return ['core' => $merged];
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
        $flatAccepted = $this->prefixWithCore(
            Arr::dot(Arr::only($accepted, ['rbac', 'audit', 'evidence', 'avatars']))
        );

        $baseline = $this->prefixWithCore(Arr::dot((array) config('core', [])));
        $current  = $this->currentOverrides();

        $desired = $current;

        foreach ($flatAccepted as $key => $val) {
            $default = $baseline[$key] ?? null;

            if ($this->valuesEqual($val, $default)) {
                if (array_key_exists($key, $desired)) {
                    unset($desired[$key]);
                }
                continue;
            }

            $desired[$key] = $val;
        }

        $touchedKeys = array_unique(array_keys($flatAccepted));
        $becameUnset = array_diff(array_keys($current), array_keys($desired));
        foreach ($becameUnset as $k) {
            if (in_array($k, $touchedKeys, true)) {
                $touchedKeys[] = $k;
            }
        }

        if ($this->persistenceAvailable()) {
            $toDelete = array_values(array_intersect($becameUnset, $touchedKeys));
            $toUpsert = [];
            foreach ($touchedKeys as $k) {
                $cur = $current[$k] ?? null;
                $des = $desired[$k] ?? null;
                if ($cur === null && $des !== null) {
                    $toUpsert[$k] = $des;
                } elseif ($des !== null && !$this->valuesEqual($cur, $des)) {
                    $toUpsert[$k] = $des;
                }
            }

            DB::transaction(function () use ($toDelete, $toUpsert, $actorId): void {
                if ($toDelete !== []) {
                    Setting::query()->whereIn('key', $toDelete)->delete();
                }
                if ($toUpsert !== []) {
                    $rows = [];
                    $now = now();
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

        $changes = [];
        foreach ($touchedKeys as $k) {
            $old = $current[$k] ?? null;
            $new = $desired[$k] ?? null;

            if ($old === null && $new === null) {
                continue;
            }

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

        $out = [];
        foreach ($rows as $row) {
            /** @var mixed $val */
            $val = $row->value; // cast to PHP via $casts
            $out[$row->key] = $val;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    private function prefixWithCore(array $flat): array
    {
        $out = [];
        foreach ($flat as $k => $v) {
            if (
                str_starts_with($k, 'rbac.') ||
                str_starts_with($k, 'audit.') ||
                str_starts_with($k, 'evidence.') ||
                str_starts_with($k, 'avatars.')
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

    /** @param array<int|string, mixed> $arr */
    private function normalizeArray(array $arr): array
    {
        $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
        if ($isAssoc) {
            ksort($arr);
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    $arr[$k] = $this->normalizeArray($v);
                }
            }
            return $arr;
        }

        $allScalars = true;
        foreach ($arr as $v) {
            if (!is_scalar($v) && !is_null($v)) {
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
}

