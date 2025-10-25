<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthTokenCookie
{
    private const DEFAULT_NAME = 'phpgrc_token';

    private const DEFAULT_TTL_MINUTES = 120;

    /**
     * @return non-empty-string
     */
    public static function name(): string
    {
        /** @var mixed $configured */
        $configured = config('core.auth.token_cookie.name');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_NAME;
    }

    /**
     * Minutes before the token should expire server-side and client-side.
     */
    public static function ttlMinutes(): int
    {
        /** @var mixed $raw */
        $raw = config('core.auth.token_cookie.ttl_minutes');
        if (is_int($raw) && $raw >= 1) {
            return $raw;
        }
        if (is_numeric($raw)) {
            $numeric = (int) $raw;
            if ($numeric >= 1) {
                return $numeric;
            }
        }

        /** @var mixed $fallbackRaw */
        $fallbackRaw = config('session.lifetime');
        if (is_int($fallbackRaw) && $fallbackRaw >= 1) {
            return $fallbackRaw;
        }
        if (is_numeric($fallbackRaw)) {
            $converted = (int) $fallbackRaw;
            if ($converted >= 1) {
                return $converted;
            }
        }

        return self::DEFAULT_TTL_MINUTES;
    }

    /**
     * Compute the Carbon expiration timestamp for Sanctum tokens.
     */
    public static function expiresAt(): CarbonInterface
    {
        return now()->addMinutes(self::ttlMinutes());
    }

    /**
     * Return the configured SameSite mode (lax|strict|none).
     */
    public static function sameSite(): string
    {
        /** @var mixed $raw */
        $raw = config('core.auth.token_cookie.same_site', 'lax');
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        if (! in_array($value, ['lax', 'strict', 'none'], true)) {
            return 'lax';
        }

        return $value;
    }

    /**
     * Build a cookie that stores the PAT securely.
     */
    public static function issue(string $token, Request $request): Cookie
    {
        /** @var mixed $domain */
        $domain = config('session.domain');
        $secure = self::shouldSecure($request);

        $sameSite = self::sameSite();
        if ($sameSite === 'none' && ! $secure) {
            $secure = true;
        }

        return cookie(
            self::name(),
            $token,
            self::ttlMinutes(),
            '/',
            is_string($domain) && $domain !== '' ? $domain : null,
            $secure,
            true,
            false,
            $sameSite
        );
    }

    /**
     * Build an expired cookie to clear the token on logout.
     */
    public static function forget(Request $request): Cookie
    {
        /** @var mixed $domain */
        $domain = config('session.domain');
        $secure = self::shouldSecure($request);

        $sameSite = self::sameSite();
        if ($sameSite === 'none' && ! $secure) {
            $secure = true;
        }

        return cookie(
            self::name(),
            '',
            -1,
            '/',
            is_string($domain) && $domain !== '' ? $domain : null,
            $secure,
            true,
            false,
            $sameSite
        );
    }

    private static function shouldSecure(Request $request): bool
    {
        /** @var mixed $rawSecure */
        $rawSecure = config('core.auth.token_cookie.secure');
        $explicit = self::normalizeBoolean($rawSecure);
        if ($explicit !== null) {
            return $explicit;
        }

        if (is_string($rawSecure)) {
            $mode = strtolower(trim($rawSecure));
            if ($mode === 'inherit') {
                $inherited = self::normalizeBoolean(config('session.secure'));
                if ($inherited !== null) {
                    return $inherited;
                }
            }
        }

        $sessionSecure = self::normalizeBoolean(config('session.secure'));
        if ($sessionSecure === true) {
            return true;
        }

        return $request->isSecure();
    }

    private static function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }
}
