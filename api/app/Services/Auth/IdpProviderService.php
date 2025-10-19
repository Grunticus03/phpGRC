<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Auth\Idp\IdpDriverRegistry;
use App\Models\IdpProvider;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Uid\Ulid;

use function ctype_digit;

/**
 * Application service for managing external Identity Providers.
 */
final class IdpProviderService
{
    public function __construct(
        private readonly IdpDriverRegistry $drivers,
        private readonly AuditLogger $audit
    ) {}

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

        $this->logAudit('idp.provider.created', $provider, [
            'config_keys' => $this->configKeys($provider),
        ]);

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

        $payload = $this->sanitizeAttributes($attributes, isUpdate: true, currentDriver: $provider->driver);

        $changes = [];

        /** @var IdpProvider $updated */
        $updated = DB::transaction(function () use ($provider, $payload, &$changes): IdpProvider {
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
            /** @var array<string,mixed> $dirty */
            $dirty = $provider->getDirty();
            $provider->save();

            $changes = $this->summarizeChanges($dirty);

            return $provider->refresh();
        });

        $meta = [
            'changes' => $changes,
        ];

        if (array_key_exists('config', $payload)) {
            $meta['config_keys'] = $this->configKeys($updated);
        }

        $this->logAudit('idp.provider.updated', $updated, $meta);

        return $updated;
    }

    public function delete(IdpProvider $provider): void
    {
        if (! $this->persistenceAvailable()) {
            throw new InvalidArgumentException('IdP provider persistence is unavailable.');
        }

        $snapshot = clone $provider;

        DB::transaction(function () use ($provider): void {
            $order = $provider->evaluation_order;
            $provider->delete();
            $this->shiftRangeDown($order + 1, null, $provider->id, collapse: true);
        });

        $this->logAudit('idp.provider.deleted', $snapshot, [
            'was_enabled' => $snapshot->enabled,
            'evaluation_order' => $snapshot->evaluation_order,
        ]);
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

    public function checkHealth(IdpProvider $provider): IdpHealthCheckResult
    {
        if (! $this->persistenceAvailable()) {
            throw new InvalidArgumentException('IdP provider persistence is unavailable.');
        }

        $driver = $this->drivers->get($provider->driver);
        $result = $driver->checkHealth($this->readConfig($provider));

        /** @var IdpProvider $updated */
        $updated = DB::transaction(function () use ($provider, $result): IdpProvider {
            /** @var IdpProvider|null $current */
            $current = IdpProvider::query()
                ->whereKey($provider->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new InvalidArgumentException('Identity provider not found.');
            }

            $current->meta = $this->applyHealthMeta(
                is_array($current->meta) ? $current->meta : null,
                $result
            );

            if ($result->isHealthy()) {
                $current->last_health_at = $result->checkedAt;
            }

            $current->save();

            return $current->refresh();
        });

        $provider->setRawAttributes($updated->getAttributes(), true);

        $this->logAudit('idp.provider.health_checked', $updated, [
            'status' => $result->status,
            'message' => $result->message,
        ]);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function sanitizeAttributes(array $attributes, bool $isUpdate = false, ?string $currentDriver = null): array
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

        $effectiveDriver = null;
        if ($currentDriver !== null) {
            $effectiveDriver = $this->normalizeDriver($currentDriver);
            if (! $this->drivers->has($effectiveDriver)) {
                throw ValidationException::withMessages([
                    'driver' => ['Unsupported provider driver.'],
                ]);
            }
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
            $normalizedDriver = $this->normalizeDriver($rawDriver);
            if (! $this->drivers->has($normalizedDriver)) {
                throw ValidationException::withMessages([
                    'driver' => ['Unsupported provider driver.'],
                ]);
            }
            if ($effectiveDriver !== null && $normalizedDriver !== $effectiveDriver && $isUpdate && ! array_key_exists('config', $payload)) {
                throw ValidationException::withMessages([
                    'config' => ['Configuration must be provided when changing provider driver.'],
                ]);
            }
            $payload['driver'] = $normalizedDriver;
            $effectiveDriver = $normalizedDriver;
        }

        if ($effectiveDriver === null && array_key_exists('config', $payload)) {
            throw ValidationException::withMessages([
                'driver' => ['Driver is required when updating configuration.'],
            ]);
        }

        if (array_key_exists('enabled', $payload)) {
            $payload['enabled'] = (bool) $payload['enabled'];
        }

        if (array_key_exists('evaluation_order', $payload)) {
            /** @var mixed $rawOrder */
            $rawOrder = $payload['evaluation_order'];
            if (is_string($rawOrder) && is_numeric($rawOrder)) {
                $rawOrder = (int) $rawOrder;
            }

            if (! is_int($rawOrder)) {
                throw new InvalidArgumentException('Provider evaluation_order must be an integer.');
            }

            $payload['evaluation_order'] = max(1, $rawOrder);
        }

        if (array_key_exists('config', $payload)) {
            /** @var mixed $rawConfig */
            $rawConfig = $payload['config'];
            if (! is_array($rawConfig)) {
                throw new InvalidArgumentException('Provider config must be an array.');
            }

            if ($effectiveDriver === null) {
                throw ValidationException::withMessages([
                    'driver' => ['Driver must be provided when supplying configuration.'],
                ]);
            }

            /** @var array<string,mixed> $configArray */
            $configArray = $rawConfig;
            $driver = $this->drivers->get($effectiveDriver);
            $payload['config'] = $driver->normalizeConfig($configArray);
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
                $payload['last_health_at'] = CarbonImmutable::parse($lastHealthAt)->utc();
            } elseif ($lastHealthAt instanceof \DateTimeInterface) {
                $payload['last_health_at'] = CarbonImmutable::instance($lastHealthAt)->utc();
            } else {
                throw new InvalidArgumentException('Provider last_health_at must be an ISO8601 string or DateTimeInterface.');
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function readConfig(IdpProvider $provider): array
    {
        /** @var mixed $config */
        $config = $provider->getAttribute('config');

        if ($config === null) {
            return [];
        }

        if ($config instanceof \ArrayObject) {
            /** @var array<string,mixed> $copy */
            $copy = $config->getArrayCopy();

            return $copy;
        }

        if ($config instanceof \Traversable) {
            /** @var array<string,mixed> $array */
            $array = iterator_to_array($config);

            return $array;
        }

        if (is_array($config)) {
            /** @var array<string,mixed> $config */
            return $config;
        }

        /** @var array<string,mixed> $cast */
        $cast = (array) $config;

        return $cast;
    }

    /**
     * @return list<string>
     */
    private function configKeys(IdpProvider $provider): array
    {
        /** @var list<string> $keys */
        $keys = array_keys($this->readConfig($provider));

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $dirty
     * @return array<string,mixed>
     *
     * @psalm-suppress MixedAssignment
     */
    private function summarizeChanges(array $dirty): array
    {
        /** @var array<string,mixed> $summary */
        $summary = [];
        foreach (['name', 'driver', 'enabled', 'evaluation_order', 'last_health_at'] as $key) {
            if (array_key_exists($key, $dirty)) {
                /** @var mixed $value */
                $value = $dirty[$key];
                $summary[$key] = $value;
            }
        }

        if (array_key_exists('config', $dirty)) {
            $summary['config'] = 'updated';
        }

        if (array_key_exists('meta', $dirty)) {
            $summary['meta'] = 'updated';
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>|null  $meta
     * @return array<string,mixed>
     *
     * @psalm-suppress MixedAssignment
     */
    private function applyHealthMeta(?array $meta, IdpHealthCheckResult $result): array
    {
        $meta = is_array($meta) ? $meta : [];

        $entry = [
            'status' => $result->status,
            'message' => $result->message,
            'checked_at' => $result->checkedAt->toIso8601String(),
        ];

        if ($result->details !== []) {
            $entry['details'] = $result->details;
        }

        /** @var mixed $previousHealthRaw */
        $previousHealthRaw = $meta['health'] ?? null;
        $previousHealth = is_array($previousHealthRaw) ? $previousHealthRaw : null;

        if ($result->isHealthy()) {
            $entry['last_success_at'] = $result->checkedAt->toIso8601String();
        } elseif (is_array($previousHealth) && is_string($previousHealth['last_success_at'] ?? null)) {
            /** @var string $lastSuccess */
            $lastSuccess = $previousHealth['last_success_at'];
            $entry['last_success_at'] = $lastSuccess;
        }

        $meta['health'] = $entry;

        return $meta;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function logAudit(string $action, IdpProvider $provider, array $meta = []): void
    {
        if ($action === '') {
            throw new InvalidArgumentException('Audit action must not be empty.');
        }

        $entityId = $provider->id;
        if ($entityId === '') {
            throw new InvalidArgumentException('Provider identifier is required for auditing.');
        }

        $context = $this->auditRequestContext();

        $baseMeta = [
            'provider_key' => $provider->key,
            'driver' => $provider->driver,
            'enabled' => $provider->enabled,
        ];

        $normalizedMeta = $this->normalizeAuditMeta(array_merge($baseMeta, $meta));

        $this->audit->log([
            'actor_id' => $context['actor_id'],
            'ip' => $context['ip'],
            'ua' => $context['ua'],
            'action' => $action,
            'category' => 'AUTH',
            'entity_type' => 'idp.provider',
            'entity_id' => $entityId,
            'meta' => $normalizedMeta,
        ]);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     *
     * @psalm-suppress MixedAssignment
     */
    private function normalizeAuditMeta(array $meta): array
    {
        $normalized = [];

        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }

            $stringKey = $key;

            if ($value instanceof CarbonImmutable) {
                $normalized[$stringKey] = $value->toIso8601String();

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $normalized[$stringKey] = CarbonImmutable::instance($value)->toIso8601String();

                continue;
            }

            if (is_array($value)) {
                /** @var array<array-key,mixed> $value */
                $nested = [];
                foreach ($value as $nestedKey => $nestedValue) {
                    /** @var mixed $nestedValue */
                    $nested[(string) $nestedKey] = $nestedValue;
                }
                $normalized[$stringKey] = $this->normalizeAuditMeta($nested);

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $normalized[$stringKey] = $value;

                continue;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                $normalized[$stringKey] = (string) $value;

                continue;
            }

            try {
                $normalized[$stringKey] = json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $normalized[$stringKey] = 'unserializable';
            }
        }

        return $normalized;
    }

    /**
     * @return array{actor_id:int|null, ip:string|null, ua:string|null}
     */
    private function auditRequestContext(): array
    {
        $request = request();
        $ip = $request->ip();
        $ua = $request->userAgent();

        $actor = Auth::id();
        $actorId = null;
        if (is_int($actor)) {
            $actorId = $actor;
        } elseif (is_string($actor) && ctype_digit($actor)) {
            $actorId = (int) $actor;
        }

        return [
            'actor_id' => $actorId,
            'ip' => $ip,
            'ua' => $ua,
        ];
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
            ->trim()
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
