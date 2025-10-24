<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\AuditEvent;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OidcAuthenticator;
use App\Services\Auth\OidcProviderMetadataService;
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

    public function test_authenticator_accepts_adfs_email_claim(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-adfs',
            'name' => 'OIDC ADFS',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-adfs',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'adfs-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-adfs',
            'exp' => time() + 3600,
            'sub' => 'subject-adfs',
            'name' => 'ADFS User',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress' => 'adfs-user@example.test',
        ], $secret, 'HS256', 'adfs-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'adfs-key',
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

        $this->assertSame('adfs-user@example.test', $user->email);
        $this->assertSame('ADFS User', $user->name);
    }

    public function test_authenticator_accepts_upn_claim(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-upn',
            'name' => 'OIDC UPN',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-upn',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'upn-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-upn',
            'exp' => time() + 3600,
            'sub' => 'subject-upn',
            'upn' => 'upn-user@example.test',
            'name' => 'UPN User',
        ], $secret, 'HS256', 'upn-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'upn-key',
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

        $this->assertSame('upn-user@example.test', $user->email);
        $this->assertSame('UPN User', $user->name);
    }

    public function test_existing_user_name_not_overwritten_when_only_email_available(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-existing-name',
            'name' => 'OIDC Existing Name',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-existing',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $existing = User::create([
            'name' => 'Keeps Name',
            'email' => 'existing@example.test',
            'password' => bcrypt('secret'),
        ]);

        $secret = 'existing-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-existing',
            'exp' => time() + 3600,
            'sub' => 'subject-existing',
            'upn' => 'existing@example.test',
            'email' => 'existing@example.test',
        ], $secret, 'HS256', 'existing-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'existing-key',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $authenticator = $this->makeAuthenticator($client);

        $request = Request::create('/auth/oidc/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $authenticator->authenticate($provider, ['id_token' => $idToken], $request);

        $existing->refresh();
        $this->assertSame('Keeps Name', $existing->name);
    }

    public function test_authenticator_uses_unique_name_when_present(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-unique-name',
            'name' => 'OIDC Unique Name',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-unique',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'unique-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-unique',
            'exp' => time() + 3600,
            'sub' => 'subject-unique',
            'unique_name' => 'ACME\\jdoe',
            'upn' => 'jdoe@example.test',
            'email' => 'jdoe@example.test',
        ], $secret, 'HS256', 'unique-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'unique-key',
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

        $this->assertSame('jdoe@example.test', $user->email);
        $this->assertSame('jdoe', $user->name);
    }

    public function test_authenticator_prefers_display_name_claim(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-display-name',
            'name' => 'OIDC Display Name',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-display',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'display-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-display',
            'exp' => time() + 3600,
            'sub' => 'subject-display',
            'upn' => 'fallback@example.test',
            'http://schemas.microsoft.com/identity/claims/displayname' => 'Display Name User',
        ], $secret, 'HS256', 'display-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'display-key',
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

        $this->assertSame('Display Name User', $user->name);
    }

    public function test_authenticator_accepts_xmlsoap_displayname_claim(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-display-xml',
            'name' => 'OIDC Display XML',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-display-xml',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'display-xml-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-display-xml',
            'exp' => time() + 3600,
            'sub' => 'subject-display-xml',
            'email' => 'xml-user@example.test',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname' => 'XML Display Name',
        ], $secret, 'HS256', 'display-xml-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'display-xml-key',
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

        $this->assertSame('XML Display Name', $user->name);
    }

    public function test_authenticator_combines_given_and_family_from_adfs_urn_claims(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-urn-name',
            'name' => 'OIDC URN Name',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://adfs.example.test',
                'client_id' => 'client-urn',
                'client_secret' => 'client-secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $secret = 'urn-secret';
        $idToken = JWT::encode([
            'iss' => 'https://adfs.example.test',
            'aud' => 'client-urn',
            'exp' => time() + 3600,
            'sub' => 'subject-urn',
            'upn' => 'urn-user@example.test',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname' => 'Ada',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname' => 'Lovelace',
        ], $secret, 'HS256', 'urn-key');

        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'token_endpoint' => 'https://adfs.example.test/token',
                'jwks_uri' => 'https://adfs.example.test/jwks',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'kty' => 'oct',
                        'k' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                        'use' => 'sig',
                        'alg' => 'HS256',
                        'kid' => 'urn-key',
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

        $this->assertSame('Ada Lovelace', $user->name);
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

        $logger = $this->app->make(LoggerInterface::class);
        $metadata = new OidcProviderMetadataService($client, $cache, $logger);

        return new OidcAuthenticator(
            $client,
            $cache,
            $this->app->make(\App\Services\Audit\AuditLogger::class),
            $logger,
            $metadata
        );
    }
}
