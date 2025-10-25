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
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\NativeLdapClient;
use App\Services\Auth\LdapAuthenticator;
use App\Services\Auth\OidcAuthenticator;
use App\Services\Auth\OidcProviderMetadataService;
use App\Services\Auth\SamlAuthenticator;
use App\Services\Auth\SamlLibraryBridge;
use App\Services\Auth\SamlLibraryFactory;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\Services\Auth\SamlStateTokenFactory;
use App\Services\Auth\SamlStateTokenSigner;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class IdpServiceProvider extends ServiceProvider
{
    /**
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('saml.library.config', function (Container $app): array {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            /** @var array<string,mixed> $settings */
            $settings = (array) $config->get('saml', []);

            return $settings;
        });

        $this->app->singleton(SamlLibraryFactory::class, function (Container $app): SamlLibraryFactory {
            return new SamlLibraryFactory(
                $app->make(ConfigRepository::class),
                $app->make(SamlServiceProviderConfigResolver::class)
            );
        });

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

        $this->app->singleton(SamlStateTokenSigner::class, function (): SamlStateTokenSigner {
            $state = config('core.auth.saml.state', []);
            if (! is_array($state)) {
                throw new RuntimeException('SAML state configuration must be an array.');
            }

            $secret = $state['secret'] ?? null;
            if (! is_string($secret) || trim($secret) === '') {
                throw new RuntimeException('SAML state signing secret (core.auth.saml.state_secret) is not configured.');
            }

            /** @var mixed $previousRaw */
            $previousRaw = $state['previous_secret'] ?? null;
            $previous = null;
            if (is_string($previousRaw)) {
                $previousRaw = trim($previousRaw);
                if ($previousRaw !== '') {
                    $previous = $previousRaw;
                }
            }

            /** @var mixed $issuerRaw */
            $issuerRaw = $state['issuer'] ?? 'phpgrc.saml.state';
            $issuer = is_string($issuerRaw) && trim($issuerRaw) !== '' ? trim($issuerRaw) : 'phpgrc.saml.state';

            $audience = config('saml.sp.assertionConsumerService.url');
            if (! is_string($audience) || trim($audience) === '') {
                throw new RuntimeException('SAML SP ACS URL must be configured before issuing RelayState tokens.');
            }

            return new SamlStateTokenSigner($issuer, trim($audience), $secret, $previous);
        });

        $this->app->singleton(SamlStateTokenFactory::class, function (Container $app): SamlStateTokenFactory {
            $state = config('core.auth.saml.state', []);
            if (! is_array($state)) {
                throw new RuntimeException('SAML state configuration must be an array.');
            }

            /** @var mixed $ttlRaw */
            $ttlRaw = $state['ttl_seconds'] ?? 300;
            $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : 300;
            if ($ttl <= 0) {
                $ttl = 300;
            }

            /** @var mixed $skewRaw */
            $skewRaw = $state['clock_skew_seconds'] ?? 30;
            $skew = is_numeric($skewRaw) ? (int) $skewRaw : 30;
            if ($skew < 0) {
                $skew = 0;
            }

            $enforceRaw = array_key_exists('enforce_client_hash', $state)
                ? filter_var($state['enforce_client_hash'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null;
            $enforceHash = $enforceRaw ?? true;

            return new SamlStateTokenFactory(
                $app->make(CacheRepository::class),
                $app->make(SamlStateTokenSigner::class),
                $ttl,
                $skew,
                $enforceHash
            );
        });

        $this->app->singleton(SamlLibraryBridge::class, function (Container $app): SamlLibraryBridge {
            return new SamlLibraryBridge(
                $app->make(SamlLibraryFactory::class),
                $app->make(SamlStateTokenFactory::class),
                $app->make(LoggerInterface::class)
            );
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
                $app->make(SamlLibraryBridge::class),
                $app->make(HasherContract::class),
                $app->make(User::class)
            );
        });

        $this->app->alias(SamlAuthenticatorContract::class, SamlAuthenticator::class);
    }
}
