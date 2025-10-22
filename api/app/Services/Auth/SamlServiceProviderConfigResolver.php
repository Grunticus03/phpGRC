<?php

declare(strict_types=1);

namespace App\Services\Auth;

final class SamlServiceProviderConfigResolver
{
    /**
     * @return array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,certificate?:string}
     */
    public function resolve(): array
    {
        /** @var array<string,mixed> $raw */
        $raw = (array) config('core.auth.saml.sp', []);

        $appUrl = $this->applicationUrl();
        $signAuthnRequests = $this->normalizeBoolean($raw['sign_authn_requests'] ?? null, false);
        $wantAssertionsSigned = $this->normalizeBoolean($raw['want_assertions_signed'] ?? null, true);

        $resolved = [
            'entity_id' => $this->normalize($raw['entity_id'] ?? null, $appUrl.'/saml/sp'),
            'acs_url' => $this->normalize($raw['acs_url'] ?? null, $appUrl.'/auth/saml/acs'),
            'metadata_url' => $this->normalize($raw['metadata_url'] ?? null, $appUrl.'/auth/saml/metadata'),
            'sign_authn_requests' => $signAuthnRequests,
            'want_assertions_signed' => $wantAssertionsSigned,
        ];

        $certificate = $this->normalizeOptional($raw['certificate'] ?? null);
        if ($certificate !== null) {
            $resolved['certificate'] = $certificate;
        }

        return $resolved;
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
}
