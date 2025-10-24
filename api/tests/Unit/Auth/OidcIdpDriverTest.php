<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Idp\Drivers\OidcIdpDriver;
use App\Auth\Idp\DTO\IdpHealthCheckResult;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OidcIdpDriverTest extends TestCase
{
    /**
     * @param  list<Response>  $responses
     */
    private function makeDriver(array $responses): OidcIdpDriver
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $client = new Client([
            'handler' => $handler,
            'http_errors' => false,
        ]);

        return new OidcIdpDriver($client, new NullLogger);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseConfig(): array
    {
        return [
            'issuer' => 'https://idp.example',
            'client_id' => 'client-id',
            'client_secret' => 'super-secret',
            'redirect_uris' => ['https://app.example/auth/callback'],
            'scopes' => ['openid'],
        ];
    }

    public function test_normalize_allows_http_issuer(): void
    {
        $driver = $this->makeDriver([]);

        $config = $driver->normalizeConfig([
            'issuer' => 'http://idp.local.test',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);

        self::assertSame('http://idp.local.test', $config['issuer']);
    }

    public function test_check_health_fails_when_client_secret_rejected(): void
    {
        $driver = $this->makeDriver([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'issuer' => 'https://idp.example',
                'token_endpoint' => 'https://idp.example/token',
            ], JSON_THROW_ON_ERROR)),
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_client',
            ], JSON_THROW_ON_ERROR)),
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_client',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $driver->checkHealth($this->baseConfig());

        self::assertSame(IdpHealthCheckResult::STATUS_ERROR, $result->status);
        self::assertStringContainsString('client credentials', strtolower($result->message));
    }

    public function test_check_health_returns_warning_when_token_endpoint_requires_additional_configuration(): void
    {
        $driver = $this->makeDriver([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'issuer' => 'https://idp.example',
                'token_endpoint' => 'https://idp.example/token',
            ], JSON_THROW_ON_ERROR)),
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'unauthorized_client',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $driver->checkHealth($this->baseConfig());

        self::assertSame(IdpHealthCheckResult::STATUS_WARNING, $result->status);
        self::assertStringContainsString('additional configuration', strtolower($result->message));
    }

    public function test_check_health_succeeds_when_token_endpoint_accepts_credentials(): void
    {
        $driver = $this->makeDriver([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'issuer' => 'https://idp.example',
                'token_endpoint' => 'https://idp.example/token',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'token',
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $driver->checkHealth($this->baseConfig());

        self::assertSame(IdpHealthCheckResult::STATUS_OK, $result->status);
        self::assertStringContainsString('validated', strtolower($result->message));
    }
}
