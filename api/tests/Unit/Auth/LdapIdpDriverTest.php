<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Idp\Drivers\LdapIdpDriver;
use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\LdapException;
use Mockery;
use Tests\TestCase;

final class LdapIdpDriverTest extends TestCase
{
    public function test_normalize_config_service_strategy(): void
    {
        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $driver = new LdapIdpDriver($client);

        $config = [
            'host' => 'ldap.example.test',
            'base_dn' => 'dc=example,dc=test',
            'bind_dn' => 'cn=service,dc=example,dc=test',
            'bind_password' => 'secret',
            'use_ssl' => false,
            'timeout' => 30,
        ];

        $normalized = $driver->normalizeConfig($config);

        $this->assertSame('service', $normalized['bind_strategy']);
        $this->assertSame(389, $normalized['port']);
        $this->assertSame('(&(objectClass=person)(uid={{username}}))', $normalized['user_filter']);
        $this->assertFalse($normalized['use_ssl']);
        $this->assertFalse($normalized['start_tls']);
        $this->assertArrayHasKey('bind_password', $normalized);
        $this->assertSame('mail', $normalized['email_attribute']);
        $this->assertSame('cn', $normalized['name_attribute']);
        $this->assertSame('uid', $normalized['username_attribute']);
    }

    public function test_normalize_config_direct_strategy(): void
    {
        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $driver = new LdapIdpDriver($client);

        $config = [
            'host' => 'ldap.example.test',
            'base_dn' => 'dc=example,dc=test',
            'bind_strategy' => 'direct',
            'user_filter' => '(uid={{username}})',
            'user_dn_template' => 'uid={{username}},dc=example,dc=test',
            'use_ssl' => true,
        ];

        $normalized = $driver->normalizeConfig($config);

        $this->assertSame('direct', $normalized['bind_strategy']);
        $this->assertSame(636, $normalized['port']);
        $this->assertSame('(uid={{username}})', $normalized['user_filter']);
        $this->assertArrayNotHasKey('bind_dn', $normalized);
        $this->assertArrayNotHasKey('bind_password', $normalized);
        $this->assertTrue($normalized['use_ssl']);
    }

    public function test_check_health_returns_failed_when_config_invalid(): void
    {
        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $driver = new LdapIdpDriver($client);

        $result = $driver->checkHealth([
            'host' => '',
        ]);

        $this->assertSame(IdpHealthCheckResult::STATUS_ERROR, $result->status);
        $this->assertArrayHasKey('errors', $result->details);
    }

    public function test_check_health_reports_client_failure(): void
    {
        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $driver = new LdapIdpDriver($client);

        $config = [
            'host' => 'ldap.example.test',
            'base_dn' => 'dc=example,dc=test',
            'bind_dn' => 'cn=service,dc=example,dc=test',
            'bind_password' => 'secret',
        ];

        $client->expects('checkConnection')->andThrow(new LdapException('connection failed'));

        $result = $driver->checkHealth($config);

        $this->assertSame(IdpHealthCheckResult::STATUS_ERROR, $result->status);
        $this->assertSame('connection failed', $result->details['error'] ?? null);
    }

    public function test_check_health_passes(): void
    {
        /** @var LdapClientInterface&Mockery\MockInterface $client */
        $client = Mockery::mock(LdapClientInterface::class);
        $driver = new LdapIdpDriver($client);

        $config = [
            'host' => 'ldap.example.test',
            'base_dn' => 'dc=example,dc=test',
            'bind_dn' => 'cn=service,dc=example,dc=test',
            'bind_password' => 'secret',
            'require_tls' => false,
        ];

        $client->expects('checkConnection')->once()->with(Mockery::on(function ($normalized): bool {
            return is_array($normalized)
                && ($normalized['host'] ?? null) === 'ldap.example.test'
                && ($normalized['bind_strategy'] ?? null) === 'service';
        }));

        $result = $driver->checkHealth($config);

        $this->assertSame(IdpHealthCheckResult::STATUS_OK, $result->status);
        $this->assertSame('ldap', $driver->key());
    }
}
