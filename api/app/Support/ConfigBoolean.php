<?php

declare(strict_types=1);

namespace App\Support;

final class ConfigBoolean
{
    /**
     * Resolve a configuration value as a strict boolean.
     */
    public static function value(string $key, bool $default = false): bool
    {
        /** @var mixed $raw */
        $raw = config($key);

        if ($raw === null) {
            return $default;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw !== 0;
        }

        if (is_float($raw)) {
            return (int) $raw !== 0;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $raw;
    }
}
