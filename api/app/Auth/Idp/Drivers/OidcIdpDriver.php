<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use Illuminate\Validation\ValidationException;

class OidcIdpDriver extends AbstractIdpDriver
{
    #[\Override]
    public function key(): string
    {
        return 'oidc';
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    #[\Override]
    public function normalizeConfig(array $config): array
    {
        $errors = [];

        $issuer = $this->requireUrl($config, 'issuer', $errors, true, 'Issuer must be a valid HTTPS URL.');
        $clientId = $this->requireString($config, 'client_id', $errors, 'Client ID is required.');
        $clientSecret = $this->requireString($config, 'client_secret', $errors, 'Client secret is required.');

        $scopes = $this->coerceStringList($config, 'scopes', $errors);
        if ($scopes !== []) {
            $config['scopes'] = array_values(array_unique($scopes));
        }

        $redirects = $this->coerceStringList($config, 'redirect_uris', $errors, 'Redirect URIs must be an array of URLs.');
        if ($redirects !== []) {
            $validatedRedirects = [];
            foreach ($redirects as $index => $url) {
                $validated = filter_var($url, FILTER_VALIDATE_URL);
                if ($validated === false) {
                    $this->addError($errors, "config.redirect_uris.$index", 'Redirect URI must be a valid URL.');

                    continue;
                }

                $validatedRedirects[] = $validated;
            }
            $config['redirect_uris'] = $validatedRedirects;
        }

        if ($issuer !== '') {
            $config['issuer'] = $issuer;
        }

        if ($clientId !== '') {
            $config['client_id'] = $clientId;
        }

        if ($clientSecret !== '') {
            $config['client_secret'] = $clientSecret;
        }

        $this->throwIfErrors($errors);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    public function checkHealth(array $config): IdpHealthCheckResult
    {
        try {
            $normalized = $this->normalizeConfig($config);
        } catch (ValidationException $e) {
            return IdpHealthCheckResult::failed('OIDC configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        return IdpHealthCheckResult::healthy('OIDC configuration validated.', [
            'issuer' => $normalized['issuer'] ?? null,
            'scopes' => $normalized['scopes'] ?? [],
        ]);
    }
}
