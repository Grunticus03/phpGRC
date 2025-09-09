<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Events\SettingsUpdated;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SettingsService
{
    /** @return array{core: array<string, mixed>} */
    public function effectiveConfig(): array
    {
        $defaults = (array) config('core', []);
        $overrides = $this->currentOverrides(); // ['core.audit.enabled' => false, ...]

        $merged = $defaults;
        foreach ($overrides as $dotKey => $value) {
            // only accept keys under "core."
            if (!str_starts_with($dotKey, 'core.')) {
                continue;
            }
            Arr::set($merged, substr($dotKey, 5), $value); // strip "core."
        }

        return ['core' => $merged];
    }

    /**
     * Apply accepted settings. Partial updates only affect provided keys.
     *
     * @param array<string,mixed> $accepted  // shape: ['rbac'=>..., 'audit'=>..., 'evidence'=>..., 'avatars'=>...]
     * @param int|null $actorId
     * @param array<string,mixed> $context
     * @return array{changes: array<int, array<string, mixed>>}
     */
    public function apply(array $accepted, ?int $actorId = null, array $context = []): array
    {
        // Flatten accepted to dot keys with "core." prefix and filter to whitelisted sections.
        $flatAccepted = $this->prefixWithCore(
            Arr::dot(Arr::only($accepted, ['rbac', 'audit', 'evidence', 'avatars']))
        );

        $baseline = $this->prefixWithCore(Arr::dot((array) config('core', [])));
        $current  = $this->currentOverrides();

        // Start from current overrides and update only touched keys.
        $desired = $current;

        foreach ($flatAccepted as $key => $val) {
            $default = $baseline[$key] ?? null;

            // If same as default, we do not need an override.
            if ($this->valuesEqual($val, $default)) {
                if (array_key_exists($key, $desired)) {
                    unset($desired[$key]);
                }
                continue;
            }

            $desired[$key] = $val;
        }

        // Compute delta limited to keys we touched (keys present in $flatAccepted or that became unset).
        $touchedKeys = array_unique(array_keys($flatAccepted));
        $becameUnset = array_diff(array_keys($current), array_keys($desired));
        foreach ($becameUnset as $k) {
            if (in_array($k, $touchedKeys, true)) {
                $touchedKeys[] = $k;
            }
        }

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
                        'value'      => $v,
                        'type'       => 'json',
                        'updated_by' => $actorId,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                }
                Setting::query()->upsert(
                    $rows,
                    ['key'],
                    ['value', 'type', 'updated_by', 'updated_at']
                );
            }
        });

        // Build change list for auditing.
        $changes = [];
        foreach ($touchedKeys as $k) {
            $old = $current[$k] ?? null;
            $new = $desired[$k] ?? null;

            if ($old === null && $new === null) {
                continue;
            }

            $action = $new === null ? 'unset' : ($old === null ? 'set' : 'update');
            $changes[] = [
                'key'   => $k,
                'old'   => $old,
                'new'   => $new,
                'action'=> $action,
            ];
        }

        // Fire event for audit pipeline. Listener wiring can be added later.
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
        /** @var Collection<int, array{key:string, value:mixed}> $rows */
        $rows = Setting::query()->select(['key', 'value'])->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
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
            // Ensure only whitelisted sections are persisted.
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
        // Strict compare after normalizing arrays for order-insensitive equality where applicable.
        if (is_array($a) && is_array($b)) {
            return $this->normalizeArray($a) === $this->normalizeArray($b);
        }
        return $a === $b;
    }

    /** @param array<int|string, mixed> $arr */
    private function normalizeArray(array $arr): array
    {
        // Distinguish associative vs list.
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

        // For lists of scalars, sort for stable comparison.
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

        // Leave as-is for mixed lists.
        return $arr;
    }
}
