<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Exceptions\Auth\SamlMetadataException;
use App\Services\Auth\SamlMetadataService;
use Illuminate\Validation\ValidationException;

final class SamlIdpDriver extends AbstractIdpDriver
{
    public function __construct(
        private readonly SamlMetadataService $metadata
    ) {}

    #[\Override]
    public function key(): string
    {
        return 'saml';
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

        /** @var string|null $metadataXml */
        $metadataXml = $config['metadata'] ?? $config['metadata_xml'] ?? null;
        if (is_string($metadataXml) && trim($metadataXml) !== '') {
            try {
                $parsed = $this->metadata->parse($metadataXml);
                $config = array_merge($config, $parsed);
            } catch (SamlMetadataException $e) {
                $this->addError($errors, 'config.metadata', $e->getMessage());
            }
        }

        $entityId = $this->requireString($config, 'entity_id', $errors, 'Entity ID is required.');
        $ssoUrl = $this->requireUrl($config, 'sso_url', $errors, false, 'SSO URL must be a valid URL.');
        $certificate = $this->requireString($config, 'certificate', $errors, 'Signing certificate is required.');

        if ($certificate !== '' && ! str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $this->addError($errors, 'config.certificate', 'Certificate must be a PEM encoded block.');
        }

        $this->throwIfErrors($errors);

        $config['entity_id'] = $entityId;
        $config['sso_url'] = $ssoUrl;
        $config['certificate'] = $certificate;
        unset($config['metadata'], $config['metadata_xml']);

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
            return IdpHealthCheckResult::failed('SAML configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        return IdpHealthCheckResult::healthy('SAML configuration validated.', [
            'entity_id' => $normalized['entity_id'] ?? null,
            'sso_url' => $normalized['sso_url'] ?? null,
        ]);
    }
}
