<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class AuditValueSanitizer
{
    /**
     * Placeholder returned when binary or otherwise sensitive payloads are detected.
     *
     * @var non-empty-string
     */
    private const BINARY_PLACEHOLDER = '[binary omitted]';

    /**
     * Recursively scrub a value so that audit storage never captures binary payloads.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        if (is_array($value)) {
            return self::scrubArray($value);
        }

        if ($value instanceof \JsonSerializable) {
            try {
                /** @var mixed $serialized */
                $serialized = $value->jsonSerialize();
            } catch (\Throwable) {
                return '[unserializable]';
            }

            return self::scrub($serialized);
        }

        if ($value instanceof \Stringable) {
            return self::sanitizeString((string) $value);
        }

        if (is_object($value)) {
            /** @var array<array-key, mixed> $properties */
            $properties = get_object_vars($value);

            return self::scrubArray($properties);
        }

        return $value;
    }

    public static function stringify(mixed $value): string
    {
        /** @var mixed $value */
        $value = self::scrub($value);

        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\Throwable) {
                return '[unserializable]';
            }
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @psalm-param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     *
     * @psalm-return array<array-key, mixed>
     */
    private static function scrubArray(array $values): array
    {
        /** @var array<array-key, mixed> $sanitized */
        $sanitized = [];

        foreach (array_keys($values) as $key) {
            /** @var array-key $key */
            /** @var mixed $candidate */
            $candidate = $values[$key];
            /** @psalm-suppress MixedAssignment */
            $sanitizedValue = self::scrub($candidate);
            /** @var mixed $sanitizedValue */
            /** @psalm-suppress MixedAssignment */
            $sanitized[$key] = $sanitizedValue;
        }

        return $sanitized;
    }

    private static function sanitizeString(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (
            self::looksLikeDataUri($trimmed) ||
            self::looksLikeBase64($trimmed) ||
            self::containsBinaryBytes($value) ||
            self::looksLikePemBlock($trimmed)
        ) {
            return self::BINARY_PLACEHOLDER;
        }

        return $trimmed;
    }

    private static function looksLikeDataUri(string $value): bool
    {
        return preg_match('/^data:[^,]+;base64,/i', $value) === 1;
    }

    private static function looksLikePemBlock(string $value): bool
    {
        return preg_match('/^-{5}BEGIN [A-Z0-9 ]+-{5}/', $value) === 1;
    }

    private static function looksLikeBase64(string $value): bool
    {
        $normalized = preg_replace('/\s+/', '', $value);
        if (! is_string($normalized) || $normalized === '') {
            $normalized = $value;
        }

        if (strlen($normalized) < 64) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $normalized) !== 1) {
            return false;
        }

        if (strlen($normalized) > 8192) {
            return true;
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return false;
        }

        return self::containsBinaryBytes($decoded);
    }

    private static function containsBinaryBytes(string $value): bool
    {
        if (preg_match('//u', $value) !== 1) {
            return true;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
