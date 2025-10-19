<?php

declare(strict_types=1);

namespace App\Auth\Idp;

use App\Auth\Idp\Contracts\IdpDriver;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Driver registry allowing runtime lookup of IdP implementations.
 */
final class IdpDriverRegistry
{
    /**
     * @var array<string, IdpDriver>
     */
    private array $drivers = [];

    /**
     * @param  iterable<int,IdpDriver>  $drivers
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$this->normalizeKey($driver->key())] = $driver;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($this->normalizeKey($key), $this->drivers);
    }

    public function get(string $key): IdpDriver
    {
        $normalized = $this->normalizeKey($key);
        if (! $this->has($normalized)) {
            throw new InvalidArgumentException(sprintf('Unknown IdP driver "%s".', $key));
        }

        return $this->drivers[$normalized];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }

    private function normalizeKey(string $key): string
    {
        return trim(Str::of($key)->lower()->__toString());
    }
}
