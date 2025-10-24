<?php

declare(strict_types=1);

namespace App\ValueObjects\Auth;

/**
 * Immutable representation of a SAML relay-state token payload.
 */
final class SamlStateDescriptor
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $providerId,
        public readonly string $providerKey,
        public readonly ?string $intendedPath,
        public readonly int $issuedAt,
        public readonly ?string $clientHash,
        public readonly string $issuer,
        public readonly string $audience,
        public readonly int $version,
        public readonly ?string $token = null,
        public readonly ?string $signatureKey = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $this->issuedAt,
            'ver' => $this->version,
            'rid' => $this->requestId,
            'pid' => $this->providerId,
            'pkey' => $this->providerKey,
            'dest' => $this->intendedPath,
            'fp' => $this->clientHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromPayload(array $payload, ?string $token, string $signatureKey): self
    {
        return new self(
            requestId: self::stringValue($payload['rid'] ?? null, 'rid'),
            providerId: self::stringValue($payload['pid'] ?? null, 'pid'),
            providerKey: self::stringValue($payload['pkey'] ?? null, 'pkey'),
            intendedPath: self::optionalString($payload['dest'] ?? null),
            issuedAt: self::intValue($payload['iat'] ?? null, 'iat'),
            clientHash: self::optionalString($payload['fp'] ?? null),
            issuer: self::stringValue($payload['iss'] ?? null, 'iss'),
            audience: self::stringValue($payload['aud'] ?? null, 'aud'),
            version: self::intValue($payload['ver'] ?? null, 'ver'),
            token: $token,
            signatureKey: $signatureKey,
        );
    }

    public function withToken(string $token, ?string $signatureKey = null): self
    {
        return new self(
            requestId: $this->requestId,
            providerId: $this->providerId,
            providerKey: $this->providerKey,
            intendedPath: $this->intendedPath,
            issuedAt: $this->issuedAt,
            clientHash: $this->clientHash,
            issuer: $this->issuer,
            audience: $this->audience,
            version: $this->version,
            token: $token,
            signatureKey: $signatureKey ?? $this->signatureKey,
        );
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(sprintf('SAML state token missing field "%s".', $field));
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function intValue(mixed $value, string $field): int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            throw new \UnexpectedValueException(sprintf('SAML state token missing numeric field "%s".', $field));
        }

        return (int) $value;
    }
}
