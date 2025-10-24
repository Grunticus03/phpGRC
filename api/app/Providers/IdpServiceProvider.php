<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Idp\Drivers\EntraIdpDriver;
use App\Auth\Idp\Drivers\LdapIdpDriver;
use App\Auth\Idp\Drivers\OidcIdpDriver;
use App\Auth\Idp\Drivers\SamlIdpDriver;
use App\Auth\Idp\IdpDriverRegistry;
use App\Contracts\Auth\LdapAuthenticatorContract;
use App\Contracts\Auth\OidcAuthenticatorContract;
use App\Contracts\Auth\SamlAuthenticatorContract;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\NativeLdapClient;
use App\Services\Auth\LdapAuthenticator;
use App\Services\Auth\OidcAuthenticator;
use App\Services\Auth\OidcProviderMetadataService;
use App\Services\Auth\SamlAuthenticator;
use App\Services\Auth\SamlAuthnRequestBuilder;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\Services\Auth\SamlStateStore;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class IdpServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(OidcIdpDriver::class, function (Container $app): OidcIdpDriver {
            return new OidcIdpDriver(
                $app->make(ClientInterface::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(EntraIdpDriver::class, function (Container $app): EntraIdpDriver {
            return new EntraIdpDriver(
                $app->make(ClientInterface::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(SamlAuthnRequestBuilder::class, static fn (): SamlAuthnRequestBuilder => new SamlAuthnRequestBuilder);

        $this->app->singleton(SamlStateStore::class, function (Container $app): SamlStateStore {
            return new SamlStateStore($app->make(CacheRepository::class));
        });

        $this->app->singleton(IdpDriverRegistry::class, function (Container $app): IdpDriverRegistry {
            /** @var OidcIdpDriver $oidc */
            $oidc = $app->make(OidcIdpDriver::class);
            /** @var SamlIdpDriver $saml */
            $saml = $app->make(SamlIdpDriver::class);
            /** @var LdapIdpDriver $ldap */
            $ldap = $app->make(LdapIdpDriver::class);
            /** @var EntraIdpDriver $entra */
            $entra = $app->make(EntraIdpDriver::class);

            return new IdpDriverRegistry([$oidc, $saml, $ldap, $entra]);
        });

        $this->app->bind(ClientInterface::class, static fn (): ClientInterface => new Client(['http_errors' => false]));
        $this->app->singleton(LdapClientInterface::class, NativeLdapClient::class);

        $this->app->singleton(OidcProviderMetadataService::class, function (Container $app): OidcProviderMetadataService {
            return new OidcProviderMetadataService(
                $app->make(ClientInterface::class),
                $app->make(CacheRepository::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(OidcAuthenticatorContract::class, function (Container $app): OidcAuthenticatorContract {
            return new OidcAuthenticator(
                $app->make(ClientInterface::class),
                $app->make(CacheRepository::class),
                $app->make(AuditLogger::class),
                $app->make(LoggerInterface::class),
                $app->make(OidcProviderMetadataService::class),
            );
        });

        $this->app->alias(OidcAuthenticatorContract::class, OidcAuthenticator::class);

        $this->app->singleton(LdapAuthenticatorContract::class, function (Container $app): LdapAuthenticatorContract {
            return new LdapAuthenticator(
                $app->make(LdapClientInterface::class),
                $app->make(AuditLogger::class),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->alias(LdapAuthenticatorContract::class, LdapAuthenticator::class);

        $this->app->singleton(SamlAuthenticatorContract::class, function (Container $app): SamlAuthenticatorContract {
            return new SamlAuthenticator(
                $app->make(AuditLogger::class),
                $app->make(LoggerInterface::class),
                $app->make(SamlServiceProviderConfigResolver::class)
            );
        });

        $this->app->alias(SamlAuthenticatorContract::class, SamlAuthenticator::class);
    }
}
