<?php

declare(strict_types=1);

namespace App\ValueObjects\Auth;

final class SamlLoginRequest
{
    /**
     * @param  array<string,mixed>  $parameters
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $destination,
        public readonly string $redirectUrl,
        public readonly string $encodedRequest,
        public readonly string $xml,
        public readonly ?string $relayState,
        public readonly SamlStateDescriptor $stateDescriptor,
        public readonly array $parameters
    ) {}
}
