<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

final class SamlServiceProviderConfigResolver
{
    /**
     * @return array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}
     */
    public function resolve(): array
    {
        /** @var array<string,mixed> $raw */
        $raw = (array) config('core.auth.saml.sp', []);

        $appUrl = $this->applicationUrl();
        $signAuthnRequests = $this->normalizeBoolean($raw['sign_authn_requests'] ?? null, false);
        $wantAssertionsSigned = $this->normalizeBoolean($raw['want_assertions_signed'] ?? null, true);
        $encryptAssertions = $this->normalizeBoolean($raw['want_assertions_encrypted'] ?? null, false);

        $resolved = [
            'entity_id' => $this->normalize($raw['entity_id'] ?? null, $appUrl.'/saml/sp'),
            'acs_url' => $this->normalize($raw['acs_url'] ?? null, $appUrl.'/auth/saml/acs'),
            'metadata_url' => $this->normalize($raw['metadata_url'] ?? null, $appUrl.'/auth/saml/metadata'),
            'sign_authn_requests' => $signAuthnRequests,
            'want_assertions_signed' => $wantAssertionsSigned,
            'want_assertions_encrypted' => $encryptAssertions,
        ];

        $certificate = $this->normalizeOptional($raw['certificate'] ?? null);
        if ($certificate !== null) {
            $resolved['certificate'] = $certificate;
        }

        return $resolved;
    }

    public function privateKey(): ?string
    {
        $inline = $this->normalizeOptional(config('core.auth.saml.sp.private_key'));
        if ($inline !== null) {
            return $this->normalizePrivateKey($inline);
        }

        $path = $this->normalizeOptional(config('core.auth.saml.sp.private_key_path'));
        if ($path === null) {
            return null;
        }

        $resolvedPath = $this->resolvePath($path);
        if (! is_file($resolvedPath)) {
            throw new RuntimeException(sprintf('SAML SP private key path "%s" does not exist.', $path));
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read SAML SP private key from "%s".', $resolvedPath));
        }

        $trimmed = trim($contents);

        if ($trimmed === '') {
            return null;
        }

        return $this->normalizePrivateKey($trimmed);
    }

    public function privateKeyPassphrase(): ?string
    {
        return $this->normalizeOptional(config('core.auth.saml.sp.private_key_passphrase'));
    }

    private function applicationUrl(): string
    {
        /** @var mixed $configured */
        $configured = config('app.url', 'http://localhost');
        $url = is_string($configured) ? trim($configured) : '';
        if ($url === '') {
            $url = 'http://localhost';
        }

        return rtrim($url, '/');
    }

    private function normalize(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $fallback;
    }

    private function normalizeOptional(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeBoolean(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $fallback;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private function normalizePrivateKey(string $raw): string
    {
        if (str_contains($raw, '-----BEGIN')) {
            return $raw;
        }

        $compact = preg_replace('/\\s+/', '', $raw);
        if ($compact === null || $compact === '') {
            return $raw;
        }

        $decoded = base64_decode($compact, true);
        if ($decoded === false) {
            return $raw;
        }

        $body = chunk_split($compact, 64, "\n");

        return "-----BEGIN PRIVATE KEY-----\n".$body."-----END PRIVATE KEY-----\n";
    }
}
