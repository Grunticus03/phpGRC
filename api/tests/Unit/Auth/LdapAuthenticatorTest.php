<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\AuditEvent;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\LdapException;
use App\Services\Auth\LdapAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class LdapAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticator_provisions_user_and_assigns_roles(): void
    {
        Role::query()->updateOrCreate(['id' => 'role_user'], ['name' => 'User']);
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);

        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'ldap-primary',
            'name' => 'LDAP Primary',
            'driver' => 'ldap',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'host' => 'ldap.example.test',
                'port' => 389,
                'base_dn' => 'dc=example,dc=test',
                'bind_dn' => 'cn=service,dc=example,dc=test',
                'bind_password' => 'secret',
                'bind_strategy' => 'service',
                'user_filter' => '(&(objectClass=person)(uid={{username}}))',
                'username_attribute' => 'uid',
                'email_attribute' => 'mail',
                'name_attribute' => 'cn',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => ['role_user'],
                    'role_templates' => [
                        [
                            'claim' => 'memberof',
                            'values' => ['CN=Admins,DC=example,DC=test'],
                            'roles' => ['role_admin'],
                        ],
                    ],
                ],
            ],
        ]);

        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $client->expects('authenticate')->once()->andReturn([
            'dn' => 'uid=jdoe,dc=example,dc=test',
            'attributes' => [
                'mail' => ['ldap-user@example.test'],
                'cn' => ['LDAP User'],
                'memberof' => ['CN=Admins,DC=example,DC=test'],
            ],
        ]);

        $audit = $this->app->make(\App\Services\Audit\AuditLogger::class);
        $logger = new NullLogger;

        $authenticator = new LdapAuthenticator($client, $audit, $logger);

        $request = Request::create('/auth/ldap/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);

        $user = $authenticator->authenticate($provider, [
            'provider' => $provider->id,
            'username' => 'jdoe',
            'password' => 'super-secret',
        ], $request);

        $this->assertSame('ldap-user@example.test', $user->email);
        $this->assertSame('LDAP User', $user->name);

        $roles = $user->roles()->pluck('id')->all();
        $this->assertContains('role_user', $roles);
        $this->assertContains('role_admin', $roles);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'auth.ldap.login')
                ->where('entity_id', $provider->id)
                ->exists()
        );
    }

    public function test_authenticator_respects_create_users_flag(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'ldap-existing-only',
            'name' => 'LDAP Existing Only',
            'driver' => 'ldap',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'host' => 'ldap.example.test',
                'base_dn' => 'dc=example,dc=test',
                'bind_dn' => 'cn=service,dc=example,dc=test',
                'bind_password' => 'secret',
                'bind_strategy' => 'service',
                'user_filter' => '(uid={{username}})',
                'username_attribute' => 'uid',
                'email_attribute' => 'mail',
                'name_attribute' => 'cn',
                'jit' => [
                    'create_users' => false,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $client->expects('authenticate')->once()->andReturn([
            'dn' => 'uid=missing,dc=example,dc=test',
            'attributes' => [
                'mail' => ['missing@example.test'],
            ],
        ]);

        $audit = $this->app->make(\App\Services\Audit\AuditLogger::class);
        $logger = new NullLogger;

        $authenticator = new LdapAuthenticator($client, $audit, $logger);

        $request = Request::create('/auth/ldap/login', 'POST');

        try {
            $authenticator->authenticate($provider, [
                'provider' => $provider->id,
                'username' => 'missing',
                'password' => 'password',
            ], $request);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(0, AuditEvent::query()->count());
        }
    }

    public function test_invalid_credentials_surface_as_unauthorized(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'ldap-auth',
            'name' => 'LDAP Auth',
            'driver' => 'ldap',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'host' => 'ldap.example.test',
                'base_dn' => 'dc=example,dc=test',
                'bind_dn' => 'cn=service,dc=example,dc=test',
                'bind_password' => 'secret',
                'bind_strategy' => 'service',
                'user_filter' => '(uid={{username}})',
                'username_attribute' => 'uid',
                'email_attribute' => 'mail',
                'name_attribute' => 'cn',
            ],
        ]);

        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $client->expects('authenticate')->andThrow(new LdapException('Invalid LDAP credentials.'));

        $audit = $this->app->make(\App\Services\Audit\AuditLogger::class);
        $logger = $this->makeLoggerSpy();

        $authenticator = new LdapAuthenticator($client, $audit, $logger);

        $request = Request::create('/auth/ldap/login', 'POST');

        try {
            $authenticator->authenticate($provider, [
                'provider' => $provider->id,
                'username' => 'jdoe',
                'password' => 'bad-pass',
            ], $request);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(401, $e->status);
            $this->assertCount(1, $logger->warnings);
        }
    }

    private function makeLoggerSpy(): LoggerInterface
    {
        return new class implements LoggerInterface
        {
            /** @var list<array{message: mixed, context: array}> */
            public array $warnings = [];

            public function emergency($message, array $context = []): void {}

            public function alert($message, array $context = []): void {}

            public function critical($message, array $context = []): void {}

            public function error($message, array $context = []): void {}

            public function warning($message, array $context = []): void
            {
                $this->warnings[] = ['message' => $message, 'context' => $context];
            }

            public function notice($message, array $context = []): void {}

            public function info($message, array $context = []): void {}

            public function debug($message, array $context = []): void {}

            public function log($level, $message, array $context = []): void {}
        };
    }
}
