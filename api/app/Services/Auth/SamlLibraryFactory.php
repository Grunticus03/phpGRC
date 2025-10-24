<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\Constants;
use RobRichards\XMLSecLibs\XMLSecurityKey;

final class SamlLibraryFactory
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly SamlServiceProviderConfigResolver $spConfig
    ) {}

    /**
     * @param  array<string,mixed>  $idpConfig
     * @param  array<string,mixed>  $overrides
     */
    public function make(array $idpConfig, array $overrides = []): OneLoginAuth
    {
        $settings = $this->buildSettings($idpConfig, $overrides);

        return new OneLoginAuth($settings);
    }

    /**
     * @param  array<string,mixed>  $idpConfig
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public function buildSettings(array $idpConfig, array $overrides = []): array
    {
        /** @var array<string,mixed> $settings */
        $settings = $this->stringKeyedArray($this->config->get('saml', []));
        $overridesArray = $this->stringKeyedArray($overrides);

        $settings = array_replace_recursive($settings, $overridesArray);

        $settings['sp'] = $this->buildServiceProviderSettings($this->stringKeyedArray($settings['sp'] ?? []));
        $settings['idp'] = $this->buildIdentityProviderSettings($this->stringKeyedArray($settings['idp'] ?? []), $idpConfig);
        $settings['security'] = $this->buildSecuritySettings($this->stringKeyedArray($settings['security'] ?? []));

        /** @var array<string,mixed> $settings */
        return $settings;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    private function buildServiceProviderSettings(array $settings): array
    {
        $sp = $this->spConfig->resolve();

        $settings['entityId'] = $sp['entity_id'];

        $acs = $this->stringKeyedArray($settings['assertionConsumerService'] ?? []);

        $acs['url'] = $sp['acs_url'];
        if (! isset($acs['binding']) || ! is_string($acs['binding']) || $acs['binding'] === '') {
            $acs['binding'] = Constants::BINDING_HTTP_POST;
        }
        $settings['assertionConsumerService'] = $acs;

        $metadataUrl = $sp['metadata_url'];
        if ($metadataUrl !== '') {
            $settings['metadataUrl'] = $metadataUrl;
        }

        if (isset($sp['certificate'])) {
            $settings['x509cert'] = $sp['certificate'];
        }

        $privateKey = $this->spConfig->privateKey();
        if (is_string($privateKey) && $privateKey !== '') {
            $settings['privateKey'] = $privateKey;
        }

        $passphrase = $this->spConfig->privateKeyPassphrase();
        if (is_string($passphrase) && $passphrase !== '') {
            $settings['privateKeyPassphrase'] = $passphrase;
        }

        if (! isset($settings['NameIDFormat']) || ! is_string($settings['NameIDFormat']) || $settings['NameIDFormat'] === '') {
            $settings['NameIDFormat'] = Constants::NAMEID_UNSPECIFIED;
        }

        /** @var array<string,mixed> $settings */
        return $settings;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>  $idpConfig
     * @return array<string,mixed>
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    private function buildIdentityProviderSettings(array $settings, array $idpConfig): array
    {
        $settings['entityId'] = $this->stringValue($idpConfig['entity_id'] ?? null);

        $sso = $this->stringKeyedArray($settings['singleSignOnService'] ?? []);
        $sso['url'] = $this->stringValue($idpConfig['sso_url'] ?? null);
        if (! isset($sso['binding']) || ! is_string($sso['binding']) || $sso['binding'] === '') {
            $sso['binding'] = Constants::BINDING_HTTP_REDIRECT;
        }
        $settings['singleSignOnService'] = $sso;

        if (isset($idpConfig['slo_url']) && is_string($idpConfig['slo_url']) && $idpConfig['slo_url'] !== '') {
            $slo = $this->stringKeyedArray($settings['singleLogoutService'] ?? []);
            $slo['url'] = $idpConfig['slo_url'];
            if (! isset($slo['binding']) || ! is_string($slo['binding']) || $slo['binding'] === '') {
                $slo['binding'] = Constants::BINDING_HTTP_REDIRECT;
            }
            $settings['singleLogoutService'] = $slo;
        }

        if (isset($idpConfig['certificate']) && is_string($idpConfig['certificate'])) {
            $settings['x509cert'] = $idpConfig['certificate'];
        }

        if (isset($idpConfig['x509certMulti']) && is_array($idpConfig['x509certMulti'])) {
            $settings['x509certMulti'] = $idpConfig['x509certMulti'];
        }

        /** @var array<string,mixed> $settings */
        return $settings;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function buildSecuritySettings(array $settings): array
    {
        $sp = $this->spConfig->resolve();

        $settings['authnRequestsSigned'] = $sp['sign_authn_requests'];
        $settings['wantAssertionsSigned'] = $sp['want_assertions_signed'];
        $settings['wantAssertionsEncrypted'] = $sp['want_assertions_encrypted'];

        if (! isset($settings['wantNameId']) || ! is_bool($settings['wantNameId'])) {
            $settings['wantNameId'] = true;
        }

        if (! isset($settings['wantXMLValidation']) || ! is_bool($settings['wantXMLValidation'])) {
            $settings['wantXMLValidation'] = true;
        }

        if (! isset($settings['signatureAlgorithm']) || ! is_string($settings['signatureAlgorithm']) || $settings['signatureAlgorithm'] === '') {
            $settings['signatureAlgorithm'] = XMLSecurityKey::RSA_SHA256;
        }

        /** @var array<string,mixed> $settings */
        return $settings;
    }

    private function stringValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @return array<string,mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<array-key,mixed> $valueArray */
        $valueArray = $value;

        /** @var array<string,mixed> $filtered */
        $filtered = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($valueArray as $key => $item) {
            if (is_string($key)) {
                /** @psalm-suppress MixedAssignment */
                $filtered[$key] = $item;
            }
        }

        return $filtered;
    }
}
