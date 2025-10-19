<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\SamlMetadataException;
use Carbon\CarbonImmutable;
use SimpleXMLElement;

final class SamlMetadataService
{
    private const METADATA_NAMESPACE = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const SIGNATURE_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * @return array{entity_id:string,sso_url:string,certificate:string}
     */
    public function parse(string $metadataXml): array
    {
        $metadataXml = trim($metadataXml);
        if ($metadataXml === '') {
            throw new SamlMetadataException('SAML metadata must not be empty.');
        }

        $xml = $this->loadXml($metadataXml);
        $entityDescriptor = $this->locateEntityDescriptor($xml);
        $entityDescriptor->registerXPathNamespace('md', self::METADATA_NAMESPACE);
        $entityDescriptor->registerXPathNamespace('ds', self::SIGNATURE_NAMESPACE);

        $entityId = trim((string) ($entityDescriptor['entityID'] ?? ''));
        if ($entityId === '') {
            throw new SamlMetadataException('SAML metadata is missing EntityDescriptor::entityID.');
        }

        $ssoUrl = $this->extractSsoUrl($entityDescriptor);
        $certificate = $this->extractCertificate($entityDescriptor);

        return [
            'entity_id' => $entityId,
            'sso_url' => $ssoUrl,
            'certificate' => $certificate,
        ];
    }

    /**
     * @param  array{entity_id?:string,sso_url?:string,certificate?:string}  $config
     */
    public function generate(array $config, ?CarbonImmutable $validUntil = null): string
    {
        $entityId = $config['entity_id'] ?? null;
        $ssoUrl = $config['sso_url'] ?? null;
        $certificate = $config['certificate'] ?? null;

        if (! is_string($entityId) || trim($entityId) === '') {
            throw new SamlMetadataException('SAML entity ID is required.');
        }

        if (! is_string($ssoUrl) || ! filter_var($ssoUrl, FILTER_VALIDATE_URL)) {
            throw new SamlMetadataException('SAML SSO URL must be a valid URL.');
        }

        if (! is_string($certificate) || trim($certificate) === '') {
            throw new SamlMetadataException('SAML signing certificate is required.');
        }

        $entityId = trim($entityId);
        $certificate = trim($certificate);

        $validUntil = ($validUntil ?? CarbonImmutable::now()->addDays(7))->utc();
        $ssoUrl = trim($ssoUrl);
        $certificateValue = $this->certificateToMetadataValue($certificate);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $entityDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:EntityDescriptor');
        $entityDescriptor->setAttribute('entityID', $entityId);
        $entityDescriptor->setAttribute('validUntil', $validUntil->toIso8601String());
        $entityDescriptor->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::SIGNATURE_NAMESPACE);
        $document->appendChild($entityDescriptor);

        $idpDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:IDPSSODescriptor');
        $idpDescriptor->setAttribute('protocolSupportEnumeration', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $entityDescriptor->appendChild($idpDescriptor);

        $keyDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:KeyDescriptor');
        $keyDescriptor->setAttribute('use', 'signing');
        $idpDescriptor->appendChild($keyDescriptor);

        $keyInfo = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:KeyInfo');
        $keyDescriptor->appendChild($keyInfo);

        $x509Data = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Data');
        $keyInfo->appendChild($x509Data);

        $x509Certificate = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Certificate', $certificateValue);
        $x509Data->appendChild($x509Certificate);

        $sso = $document->createElementNS(self::METADATA_NAMESPACE, 'md:SingleSignOnService');
        $sso->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
        $sso->setAttribute('Location', $ssoUrl);
        $idpDescriptor->appendChild($sso);

        return (string) $document->saveXML();
    }

    private function loadXml(string $metadataXml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string(
                $metadataXml,
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new SamlMetadataException('Unable to parse SAML metadata XML.');
        }

        return $xml;
    }

    private function locateEntityDescriptor(SimpleXMLElement $xml): SimpleXMLElement
    {
        $localName = $xml->getName();
        if ($localName === 'EntityDescriptor' || str_ends_with($localName, ':EntityDescriptor')) {
            return $xml;
        }

        $xml->registerXPathNamespace('md', self::METADATA_NAMESPACE);
        $matches = $xml->xpath('//md:EntityDescriptor');
        if ($matches === false || $matches === []) {
            throw new SamlMetadataException('SAML metadata missing EntityDescriptor.');
        }

        /** @var non-empty-array<SimpleXMLElement> $matches */
        $matches = $matches;

        /** @var SimpleXMLElement $descriptor */
        $descriptor = $matches[0];

        return $descriptor;
    }

    private function extractSsoUrl(SimpleXMLElement $entityDescriptor): string
    {
        $preferredBindings = [
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ];

        $ssoServices = $entityDescriptor->xpath('./md:IDPSSODescriptor/md:SingleSignOnService');
        if ($ssoServices === false || $ssoServices === []) {
            throw new SamlMetadataException('SAML metadata missing SingleSignOnService element.');
        }

        /** @var list<SimpleXMLElement> $services */
        $services = $ssoServices;

        foreach ($preferredBindings as $binding) {
            foreach ($services as $service) {
                $serviceBinding = trim((string) ($service['Binding'] ?? ''));
                $location = trim((string) ($service['Location'] ?? ''));
                if ($serviceBinding === $binding && $location !== '') {
                    $this->assertValidUrl($location);

                    return $location;
                }
            }
        }

        foreach ($services as $service) {
            $location = trim((string) ($service['Location'] ?? ''));
            if ($location !== '') {
                $this->assertValidUrl($location);

                return $location;
            }
        }

        throw new SamlMetadataException('SAML metadata SingleSignOnService is missing a valid Location.');
    }

    private function extractCertificate(SimpleXMLElement $entityDescriptor): string
    {
        $certificateNodes = $entityDescriptor->xpath('./md:IDPSSODescriptor/md:KeyDescriptor');
        if ($certificateNodes === false || $certificateNodes === []) {
            throw new SamlMetadataException('SAML metadata missing KeyDescriptor.');
        }

        /** @var list<SimpleXMLElement> $keyDescriptors */
        $keyDescriptors = $certificateNodes;

        $candidates = [];
        foreach ($keyDescriptors as $descriptor) {
            $use = trim((string) ($descriptor['use'] ?? ''));
            $values = $descriptor->xpath('.//ds:X509Certificate');
            if ($values === false || $values === []) {
                continue;
            }

            /** @var list<SimpleXMLElement> $values */
            $values = $values;
            foreach ($values as $value) {
                $certificate = trim((string) $value);
                if ($certificate === '') {
                    continue;
                }

                $candidates[] = [
                    'use' => $use,
                    'value' => $certificate,
                ];
            }
        }

        if ($candidates === []) {
            throw new SamlMetadataException('SAML metadata missing X509Certificate.');
        }

        $selected = null;
        foreach ($candidates as $candidate) {
            if ($candidate['use'] === 'signing') {
                $selected = $candidate['value'];

                break;
            }
        }
        if ($selected === null) {
            $selected = $candidates[0]['value'];
        }

        return $this->formatCertificate($selected);
    }

    private function formatCertificate(string $certificate): string
    {
        $normalized = str_ireplace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
            '',
            $certificate
        );

        $clean = preg_replace('/\s+/', '', $normalized);
        if ($clean === null || $clean === '') {
            throw new SamlMetadataException('SAML metadata certificate is empty.');
        }

        $body = trim(chunk_split($clean, 64, PHP_EOL));

        return sprintf(
            '-----BEGIN CERTIFICATE-----%s%s%s-----END CERTIFICATE-----',
            PHP_EOL,
            $body,
            PHP_EOL
        );
    }

    private function certificateToMetadataValue(string $certificate): string
    {
        $normalized = str_ireplace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
            '',
            $certificate
        );

        $clean = preg_replace('/\s+/', '', $normalized);
        if ($clean === null || $clean === '') {
            throw new SamlMetadataException('SAML certificate is invalid.');
        }

        return $clean;
    }

    private function assertValidUrl(string $value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new SamlMetadataException('SAML metadata SSO URL must be a valid URL.');
        }
    }
}
