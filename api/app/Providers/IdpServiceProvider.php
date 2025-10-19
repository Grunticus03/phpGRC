<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Idp\Drivers\EntraIdpDriver;
use App\Auth\Idp\Drivers\LdapIdpDriver;
use App\Auth\Idp\Drivers\OidcIdpDriver;
use App\Auth\Idp\Drivers\SamlIdpDriver;
use App\Auth\Idp\IdpDriverRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

final class IdpServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
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
    }
}
