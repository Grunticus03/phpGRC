<?php

declare(strict_types=1);

namespace App\Services\IntegrationBus;

use App\Models\IntegrationConnector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Application service for managing Integration Bus connectors.
 */
final class ConnectorService
{
    /**
     * @return LengthAwarePaginator<IntegrationConnector>
     */
    /**
     * @phpstan-return LengthAwarePaginator<int, IntegrationConnector>
     *
     * @psalm-return LengthAwarePaginator
     */
    public function paginate(int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        $paginator = IntegrationConnector::query()
            ->orderBy('name')
            ->paginate(perPage: $perPage, page: $page);

        /** @var LengthAwarePaginator<int, IntegrationConnector> $paginator */
        return $paginator;
    }

    /**
     * @return Collection<int, IntegrationConnector>
     */
    public function all(): Collection
    {
        return IntegrationConnector::query()
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes): IntegrationConnector
    {
        $payload = $this->sanitizeAttributes($attributes);

        /** @var IntegrationConnector $connector */
        $connector = DB::transaction(static function () use ($payload): IntegrationConnector {
            $connector = new IntegrationConnector;
            $connector->id = (string) Str::ulid();
            $connector->fill($payload);
            $connector->save();

            return $connector->refresh();
        });

        return $connector;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(IntegrationConnector $connector, array $attributes): IntegrationConnector
    {
        $payload = $this->sanitizeAttributes($attributes, isUpdate: true);

        /** @var IntegrationConnector $updated */
        $updated = DB::transaction(static function () use ($connector, $payload): IntegrationConnector {
            $connector->fill($payload);
            $connector->save();

            return $connector->refresh();
        });

        return $updated;
    }

    public function delete(IntegrationConnector $connector): void
    {
        DB::transaction(static function () use ($connector): void {
            $connector->delete();
        });
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
            'kind',
            'enabled',
            'config',
            'meta',
            'last_health_at',
        ]);

        if (! $isUpdate && ! array_key_exists('config', $payload)) {
            throw new InvalidArgumentException('Connector config is required.');
        }

        if (array_key_exists('key', $payload)) {
            $rawKey = $payload['key'];
            if (! is_string($rawKey) || $rawKey === '') {
                throw new InvalidArgumentException('Connector key must be a non-empty string.');
            }
            $payload['key'] = $this->normalizeKey($rawKey);
        }

        if (array_key_exists('name', $payload)) {
            $rawName = $payload['name'];
            if (! is_string($rawName) || $rawName === '') {
                throw new InvalidArgumentException('Connector name must be a non-empty string.');
            }
            $payload['name'] = $rawName;
        }

        if (array_key_exists('kind', $payload)) {
            $rawKind = $payload['kind'];
            if (! is_string($rawKind) || $rawKind === '') {
                throw new InvalidArgumentException('Connector kind must be a non-empty string.');
            }
            $payload['kind'] = $rawKind;
        }

        if (array_key_exists('enabled', $payload)) {
            $payload['enabled'] = (bool) $payload['enabled'];
        }

        if (array_key_exists('config', $payload)) {
            $config = $payload['config'];
            if (! is_array($config)) {
                throw new InvalidArgumentException('Connector config must be an array.');
            }
            /** @var array<string,mixed> $config */
            $payload['config'] = $config;
        }

        if (array_key_exists('meta', $payload)) {
            $meta = $payload['meta'];
            if ($meta !== null && ! is_array($meta)) {
                throw new InvalidArgumentException('Connector meta must be an array or null.');
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
                throw new InvalidArgumentException('Connector last_health_at must be an ISO8601 string or DateTimeInterface.');
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
            throw new InvalidArgumentException('Connector key must contain alphanumeric characters.');
        }

        return $normalized;
    }
}
