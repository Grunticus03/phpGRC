<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\Contracts\IdpDriver;
use Illuminate\Validation\ValidationException;

/**
 * Shared helpers for IdP driver implementations.
 */
abstract class AbstractIdpDriver implements IdpDriver
{
    /**
     * @param  array<string,list<string>>  $errors
     */
    protected function addError(array &$errors, string $path, string $message): void
    {
        if (! array_key_exists($path, $errors)) {
            $errors[$path] = [];
        }

        $errors[$path][] = $message;
    }

    /**
     * Ensure a configuration attribute is a non-empty trimmed string.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    protected function requireString(array &$config, string $key, array &$errors, string $message = 'This field is required.'): string
    {
        /** @var mixed $value */
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $this->addError($errors, "config.$key", $message);

            return '';
        }

        $trimmed = trim($value);
        $config[$key] = $trimmed;

        return $trimmed;
    }

    /**
     * Ensure value is a valid URL.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    protected function requireUrl(array &$config, string $key, array &$errors, string $message = 'This field must be a valid URL.'): string
    {
        $url = $this->requireString($config, $key, $errors, $message);
        if ($url === '') {
            return '';
        }

        $normalized = filter_var($url, FILTER_VALIDATE_URL);
        if ($normalized === false) {
            $this->addError($errors, "config.$key", $message);

            return '';
        }

        $sanitized = rtrim($normalized, '/');
        $config[$key] = $sanitized;

        return $sanitized;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    protected function requireHttpsUrl(
        array &$config,
        string $key,
        array &$errors,
        string $message = 'This field must be a valid URL.',
        string $httpsMessage = 'URL must use HTTPS.'
    ): string {
        $url = $this->requireUrl($config, $key, $errors, $message);
        if ($url === '') {
            return '';
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            $this->addError($errors, "config.$key", $httpsMessage);

            return '';
        }

        return $url;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return list<string>
     *
     * @psalm-suppress MixedAssignment
     */
    protected function coerceStringList(array &$config, string $key, array &$errors, string $message = 'Must be an array of strings.'): array
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return [];
        }

        if (! is_string($value) && ! is_array($value)) {
            $this->addError($errors, "config.$key", $message);

            return [];
        }

        $candidates = [];

        if (is_string($value)) {
            /** @var list<string> $parts */
            $parts = array_map(static fn (string $part): string => trim($part), explode(',', $value));
            $candidates = $parts;
        }

        if (is_array($value)) {
            /** @var list<string> $parts */
            $parts = array_map(static function ($item): string {
                if (is_string($item) || is_numeric($item)) {
                    return trim((string) $item);
                }

                return '';
            }, $value);
            $candidates = $parts;
        }

        /** @var list<string> $list */
        $list = array_values(array_filter($candidates, static fn (string $item): bool => $item !== ''));
        $config[$key] = $list;

        return $list;
    }

    /**
     * Coerce a port value from config.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    protected function coercePort(array &$config, string $key, array &$errors, string $message = 'Port must be between 1 and 65535.'): int
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') {
            $this->addError($errors, "config.$key", 'This field is required.');

            return 0;
        }

        if (is_string($value) && is_numeric($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1 || $value > 65535) {
            $this->addError($errors, "config.$key", $message);

            return 0;
        }

        $config[$key] = $value;

        return $value;
    }

    /**
     * @param  array<string,list<string>>  $errors
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    protected function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
