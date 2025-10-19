<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\IdpProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

/**
 * Application service for managing external Identity Providers.
 */
final class IdpProviderService
{
    public function persistenceAvailable(): bool
    {
        return Schema::hasTable('idp_providers');
    }

    /**
     * @return Collection<int, IdpProvider>
     */
    public function all(): Collection
    {
        return IdpProvider::query()
            ->orderBy('evaluation_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes): IdpProvider
    {
        if (! $this->persistenceAvailable()) {
            throw new InvalidArgumentException('IdP provider persistence is unavailable.');
        }

        $payload = $this->sanitizeAttributes($attributes);

        /** @var IdpProvider $provider */
        $provider = DB::transaction(function () use ($payload): IdpProvider {
            $requestedOrder = array_key_exists('evaluation_order', $payload) && is_int($payload['evaluation_order'])
                ? $payload['evaluation_order']
                : null;
            $order = $this->normalizedOrder($requestedOrder);
            $this->shiftOrdersUp($order);

            $provider = new IdpProvider;
            $provider->id = (string) Str::ulid();
            $provider->fill(array_merge($payload, ['evaluation_order' => $order]));
            $provider->save();

            return $provider->refresh();
        });

        return $provider;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(IdpProvider $provider, array $attributes): IdpProvider
    {
        if (! $this->persistenceAvailable()) {
            throw new InvalidArgumentException('IdP provider persistence is unavailable.');
        }

        $payload = $this->sanitizeAttributes($attributes, isUpdate: true);

        /** @var IdpProvider $updated */
        $updated = DB::transaction(function () use ($provider, $payload): IdpProvider {
            $originalOrder = $provider->evaluation_order;
            $requestedOrder = array_key_exists('evaluation_order', $payload) && is_int($payload['evaluation_order'])
                ? $payload['evaluation_order']
                : null;
            $targetOrder = $requestedOrder !== null
                ? $this->normalizedOrder($requestedOrder, $provider->id)
                : $originalOrder;

            if ($targetOrder !== $originalOrder) {
                $provider->evaluation_order = 0;
                $provider->save();

                if ($targetOrder < $originalOrder) {
                    $this->shiftRangeUp($targetOrder, $originalOrder - 1, $provider->id);
                } else {
                    $this->shiftRangeDown($originalOrder + 1, $targetOrder, $provider->id);
                }

                $provider->evaluation_order = $targetOrder;
            }

            $provider->fill(Arr::except($payload, ['evaluation_order']));
            $provider->save();

            return $provider->refresh();
        });

        return $updated;
    }

    public function delete(IdpProvider $provider): void
    {
        if (! $this->persistenceAvailable()) {
            throw new InvalidArgumentException('IdP provider persistence is unavailable.');
        }

        DB::transaction(function () use ($provider): void {
            $order = $provider->evaluation_order;
            $provider->delete();
            $this->shiftRangeDown($order + 1, null, $provider->id, collapse: true);
        });
    }

    public function findByIdOrKey(string $identifier): ?IdpProvider
    {
        $query = IdpProvider::query();

        if (Ulid::isValid($identifier)) {
            return $query->find($identifier);
        }

        return $query->where(['key' => $this->normalizeKey($identifier)])->first();
    }

    public function hasConfiguredProvider(): bool
    {
        if (! $this->persistenceAvailable()) {
            return false;
        }

        return IdpProvider::query()->exists();
    }

    public function hasEnabledProvider(): bool
    {
        if (! $this->persistenceAvailable()) {
            return false;
        }

        return IdpProvider::query()
            ->where('enabled', '=', true)
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function sanitizeAttributes(array $attributes, bool $isUpdate = false): array
    {
        /** @var array<string,mixed> $payload */
        $payload = Arr::only($attributes, [
            'key',
            'name',
            'driver',
            'enabled',
            'evaluation_order',
            'config',
            'meta',
            'last_health_at',
        ]);

        if (! $isUpdate && ! array_key_exists('key', $payload)) {
            throw new InvalidArgumentException('Provider key is required.');
        }

        if (! $isUpdate && ! array_key_exists('driver', $payload)) {
            throw new InvalidArgumentException('Provider driver is required.');
        }

        if (! $isUpdate && ! array_key_exists('config', $payload)) {
            throw new InvalidArgumentException('Provider config is required.');
        }

        if (array_key_exists('key', $payload)) {
            $rawKey = $payload['key'];
            if (! is_string($rawKey) || $rawKey === '') {
                throw new InvalidArgumentException('Provider key must be a non-empty string.');
            }
            $payload['key'] = $this->normalizeKey($rawKey);
        }

        if (array_key_exists('name', $payload)) {
            $rawName = $payload['name'];
            if (! is_string($rawName) || $rawName === '') {
                throw new InvalidArgumentException('Provider name must be a non-empty string.');
            }
            $payload['name'] = trim($rawName);
        }

        if (array_key_exists('driver', $payload)) {
            $rawDriver = $payload['driver'];
            if (! is_string($rawDriver) || $rawDriver === '') {
                throw new InvalidArgumentException('Provider driver must be a non-empty string.');
            }
            $payload['driver'] = $this->normalizeDriver($rawDriver);
        }

        if (array_key_exists('enabled', $payload)) {
            $payload['enabled'] = (bool) $payload['enabled'];
        }

        if (array_key_exists('evaluation_order', $payload)) {
            $rawOrder = $payload['evaluation_order'];
            if (! is_int($rawOrder) && ! (is_numeric($rawOrder) && (int) $rawOrder == $rawOrder)) {
                throw new InvalidArgumentException('Provider evaluation_order must be an integer.');
            }
            $payload['evaluation_order'] = max(1, $rawOrder);
        }

        if (array_key_exists('config', $payload)) {
            $config = $payload['config'];
            if (! is_array($config)) {
                throw new InvalidArgumentException('Provider config must be an array.');
            }
            /** @var array<string,mixed> $config */
            $payload['config'] = $config;
        }

        if (array_key_exists('meta', $payload)) {
            $meta = $payload['meta'];
            if ($meta !== null && ! is_array($meta)) {
                throw new InvalidArgumentException('Provider meta must be an array or null.');
            }
            /** @var array<string,mixed>|null $meta */
            $payload['meta'] = $meta;
        }

        if (array_key_exists('last_health_at', $payload)) {
            /** @var mixed $lastHealthAt */
            $lastHealthAt = $payload['last_health_at'];
            if ($lastHealthAt === null) {
                $payload['last_health_at'] = null;
            } elseif (is_string($lastHealthAt)) {
                $payload['last_health_at'] = \Carbon\CarbonImmutable::parse($lastHealthAt);
            } elseif (! $lastHealthAt instanceof \DateTimeInterface) {
                throw new InvalidArgumentException('Provider last_health_at must be an ISO8601 string or DateTimeInterface.');
            }
        }

        return $payload;
    }

    private function normalizeKey(string $key): string
    {
        $normalized = Str::of($key)
            ->lower()
            ->replaceMatches('/[^a-z0-9\-]/', '-')
            ->trim('-')
            ->__toString();

        if ($normalized === '') {
            throw new InvalidArgumentException('Provider key must contain alphanumeric characters.');
        }

        return $normalized;
    }

    private function normalizeDriver(string $driver): string
    {
        $normalized = Str::of($driver)
            ->lower()
            ->replaceMatches('/[^a-z0-9\._-]/', '')
            ->__toString();

        if ($normalized === '') {
            throw new InvalidArgumentException('Provider driver must contain alphanumeric characters.');
        }

        return $normalized;
    }

    private function normalizedOrder(?int $requested, ?string $ignoreId = null): int
    {
        $countQuery = IdpProvider::query();
        if ($ignoreId !== null) {
            $countQuery->where('id', '!=', $ignoreId);
        }
        $count = $countQuery->count();

        if ($requested === null) {
            return $count + 1;
        }

        $requested = max(1, $requested);

        if ($requested > $count + 1) {
            return $count + 1;
        }

        return $requested;
    }

    private function shiftOrdersUp(int $from, ?string $excludeId = null): void
    {
        $query = IdpProvider::query()
            ->where('evaluation_order', '>=', $from);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, IdpProvider> $rows */
        $rows = $query
            ->orderByDesc('evaluation_order')
            ->lockForUpdate()
            ->get();

        /** @var IdpProvider $row */
        foreach ($rows as $row) {
            $row->evaluation_order++;
            $row->save();
        }
    }

    private function shiftRangeUp(int $start, int $end, ?string $excludeId = null): void
    {
        if ($end < $start) {
            return;
        }

        $query = IdpProvider::query()
            ->whereBetween('evaluation_order', [$start, $end]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, IdpProvider> $rows */
        $rows = $query
            ->orderByDesc('evaluation_order')
            ->lockForUpdate()
            ->get();

        /** @var IdpProvider $row */
        foreach ($rows as $row) {
            $row->evaluation_order++;
            $row->save();
        }
    }

    private function shiftRangeDown(int $start, ?int $end, ?string $excludeId = null, bool $collapse = false): void
    {
        if ($end !== null && $end < $start) {
            return;
        }

        $query = IdpProvider::query()
            ->where('evaluation_order', '>=', $start);

        if ($end !== null) {
            $query->where('evaluation_order', '<=', $end);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, IdpProvider> $rows */
        $rows = $query
            ->orderBy('evaluation_order')
            ->lockForUpdate()
            ->get();

        /** @var IdpProvider $row */
        foreach ($rows as $row) {
            $row->evaluation_order--;
            if ($collapse && $row->evaluation_order < 1) {
                $row->evaluation_order = 1;
            }
            $row->save();
        }
    }
}
