<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\ValueObjects\Auth\SamlStateDescriptor;
use UnexpectedValueException;

final class SamlStateTokenSigner
{
    public const string KEY_PRIMARY = 'primary';

    public const string KEY_PREVIOUS = 'previous';

    private readonly string $primaryKey;

    private readonly ?string $previousKey;

    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        string $primarySecret,
        ?string $previousSecret = null
    ) {
        $this->primaryKey = $this->normalizeSecret($primarySecret);
        $this->previousKey = $previousSecret !== null ? $this->normalizeSecret($previousSecret) : null;
    }

    public function sign(SamlStateDescriptor $descriptor): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => self::KEY_PRIMARY,
        ];

        $payload = $descriptor->toPayload();
        $payload['iss'] = $this->issuer;
        $payload['aud'] = $this->audience;

        $encodedHeader = $this->encodeJson($header);
        $encodedPayload = $this->encodeJson($payload);
        $signature = $this->encodeSignature($encodedHeader.'.'.$encodedPayload, $this->primaryKey);

        return $encodedHeader.'.'.$encodedPayload.'.'.$signature;
    }

    public function verify(string $token): SamlStateDescriptor
    {
        [$encodedHeader, $encodedPayload, $encodedSignature] = $this->splitToken($token);

        $header = $this->decodeJson($encodedHeader);
        $payload = $this->decodeJson($encodedPayload);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new UnexpectedValueException('Unsupported SAML state token algorithm.');
        }

        $kid = self::KEY_PRIMARY;
        if (array_key_exists('kid', $header)) {
            if (! is_string($header['kid'])) {
                throw new UnexpectedValueException('Invalid SAML state token key identifier.');
            }
            $kid = $header['kid'];
        }

        $key = $this->resolveKey($kid);
        if (! $this->verifySignature($encodedHeader.'.'.$encodedPayload, $encodedSignature, $key)) {
            if ($kid !== self::KEY_PREVIOUS && $this->previousKey !== null && $this->verifySignature($encodedHeader.'.'.$encodedPayload, $encodedSignature, $this->previousKey)) {
                $kid = self::KEY_PREVIOUS;
                $key = $this->previousKey;
            } else {
                throw new UnexpectedValueException('Invalid SAML state token signature.');
            }
        }

        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new UnexpectedValueException('SAML state token issuer mismatch.');
        }

        if (($payload['aud'] ?? null) !== $this->audience) {
            throw new UnexpectedValueException('SAML state token audience mismatch.');
        }

        return SamlStateDescriptor::fromPayload($payload, $token, $kid);
    }

    public function hashClientFingerprint(string $raw, string $keyId = self::KEY_PRIMARY): string
    {
        $key = $this->keyForId($keyId);

        return hash_hmac('sha256', $raw, $key);
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function splitToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed SAML state token.');
        }

        /** @var array{0:string,1:string,2:string} $parts */
        return $parts;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->base64UrlEncode($json);
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $encoded): array
    {
        $decoded = json_decode($this->base64UrlDecode($encoded), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Invalid SAML state token JSON.');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    private function encodeSignature(string $data, string $key): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $key, true));
    }

    private function verifySignature(string $data, string $encodedSignature, string $key): bool
    {
        $expected = $this->encodeSignature($data, $key);

        return hash_equals($expected, $encodedSignature);
    }

    private function resolveKey(string $kid): string
    {
        if ($kid === self::KEY_PRIMARY) {
            return $this->primaryKey;
        }

        if ($kid === self::KEY_PREVIOUS && $this->previousKey !== null) {
            return $this->previousKey;
        }

        // Unknown kid: fall back to primary but verification will fail if signature mismatches.
        return $this->primaryKey;
    }

    private function keyForId(string $kid): string
    {
        return match ($kid) {
            self::KEY_PREVIOUS => $this->previousKey ?? $this->primaryKey,
            default => $this->primaryKey,
        };
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new UnexpectedValueException('Invalid base64 data in SAML state token.');
        }

        return $decoded;
    }

    private function normalizeSecret(string $secret): string
    {
        $trimmed = trim($secret);
        if ($trimmed === '') {
            throw new UnexpectedValueException('SAML state signing secret is empty.');
        }

        if (str_starts_with($trimmed, 'base64:')) {
            $decoded = base64_decode(substr($trimmed, 7), true);
            if ($decoded === false) {
                throw new UnexpectedValueException('Invalid base64-encoded SAML state secret.');
            }

            return $decoded;
        }

        if (str_starts_with($trimmed, 'hex:')) {
            $decoded = hex2bin(substr($trimmed, 4));
            if ($decoded === false) {
                throw new UnexpectedValueException('Invalid hex-encoded SAML state secret.');
            }

            return $decoded;
        }

        return $trimmed;
    }
}
