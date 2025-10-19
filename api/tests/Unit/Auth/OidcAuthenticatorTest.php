<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\AuditEvent;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Services\Auth\OidcAuthenticator;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class OidcAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_authenticator_creates_user_and_assigns_roles(): void
    {
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_user'], ['name' => 'User']);

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
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => ['role_user'],
                    'role_templates' => [
                        [
                            'claim' => 'groups',
                            'values' => ['Admins'],
                            'roles' => ['role_admin'],
                        ],
                    ],
                ],
            ],
        ]);

        $secret = 'top-secret-key';
        $idToken = JWT::encode([
            'iss' => 'https://sso.example.test',
            'aud' => 'client-123',
            'exp' => time() + 3600,
            'email' => 'new-user@example.test',
            'name' => 'New User',
            'groups' => ['Admins'],
            'sub' => 'subject-123',
        ], $secret, 'HS256', 'test-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://sso.example.test/token',
                'jwks_uri' => 'https://sso.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'test-key',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $authenticator = $this->makeAuthenticator($client);

        $request = Request::create('/auth/oidc/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $user = $authenticator->authenticate($provider, ['id_token' => $idToken], $request);

        $this->assertSame('new-user@example.test', $user->email);
        $this->assertSame('New User', $user->name);

        $roles = $user->roles()->pluck('id')->all();
        $this->assertContains('role_user', $roles);
        $this->assertContains('role_admin', $roles);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'auth.oidc.login')
                ->where('entity_id', $provider->id)
                ->exists()
        );
    }

    public function test_authenticator_respects_create_users_flag(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-existing',
            'name' => 'OIDC Existing Only',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://sso.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => false,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'another-secret';
        $idToken = JWT::encode([
            'iss' => 'https://sso.example.test',
            'aud' => 'client-123',
            'exp' => time() + 3600,
            'email' => 'absent-user@example.test',
            'sub' => 'subject-999',
        ], $secret, 'HS256', 'test-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://sso.example.test/token',
                'jwks_uri' => 'https://sso.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'test-key',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $authenticator = $this->makeAuthenticator($client);

        $request = Request::create('/auth/oidc/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $this->expectException(ValidationException::class);

        $authenticator->authenticate($provider, ['id_token' => $idToken], $request);
    }

    /**
     * @param  array<int,Response>  $responses
     */
    private function makeClient(array $responses): Client
    {
        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);

        return new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);
    }

    private function makeAuthenticator(Client $client): OidcAuthenticator
    {
        /** @var CacheRepository $cache */
        $cache = $this->app->make(CacheRepository::class);

        return new OidcAuthenticator(
            $client,
            $cache,
            $this->app->make(\App\Services\Audit\AuditLogger::class),
            $this->app->make(LoggerInterface::class)
        );
    }
}
