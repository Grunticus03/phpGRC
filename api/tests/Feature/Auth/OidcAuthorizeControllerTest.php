<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\IdpProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OidcAuthorizeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_redirects_with_pkce_state_and_nonce(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-primary',
            'name' => 'OIDC Primary',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://sso.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
                'scopes' => ['openid', 'profile', 'email'],
                'redirect_uris' => ['https://spa.example.test/auth/callback'],
            ],
        ]);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'authorization_endpoint' => 'https://sso.example.test/oauth2/v2.0/authorize',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $this->app->instance(\GuzzleHttp\ClientInterface::class, $client);

        $response = $this->get('/auth/oidc/authorize?provider='.$provider->id);

        $response->assertStatus(302);

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $parts = parse_url($location);
        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertSame('sso.example.test', $parts['host'] ?? null);
        $this->assertSame('/oauth2/v2.0/authorize', $parts['path'] ?? null);

        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('client-123', $query['client_id'] ?? null);
        $this->assertSame('https://spa.example.test/auth/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('openid profile email', $query['scope'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);

        $state = $query['state'] ?? null;
        $this->assertIsString($state);
        $this->assertNotSame('', $state);

        /** @var array<string,mixed>|null $statePayload */
        $statePayload = Cache::get('idp:oidc:state:'.$state);
        $this->assertIsArray($statePayload);

        $codeVerifier = $statePayload['code_verifier'] ?? null;
        $this->assertIsString($codeVerifier);
        $this->assertNotSame('', $codeVerifier);

        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $query['code_challenge'] ?? null);

        $nonce = $query['nonce'] ?? null;
        $this->assertIsString($nonce);
        $this->assertSame($statePayload['nonce'] ?? null, $nonce);
    }

    public function test_returns_error_when_authorization_endpoint_missing(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-missing',
            'name' => 'OIDC Missing',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://sso.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
            ],
        ]);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                // authorization_endpoint intentionally omitted
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $this->app->instance(\GuzzleHttp\ClientInterface::class, $client);

        $response = $this->getJson('/auth/oidc/authorize?provider='.$provider->id);

        $response->assertStatus(502)
            ->assertJsonPath('code', 'IDP_OIDC_DISCOVERY_FAILED');
    }
}
