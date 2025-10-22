<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\SamlMetadataException;
use Carbon\CarbonImmutable;
use DOMDocument;

/**
 * @SuppressWarnings("PHPMD.NPathComplexity")
 */
final class SamlServiceProviderMetadataBuilder
{
    private const METADATA_NAMESPACE = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const PROTOCOL_BINDING = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    private const SIGNATURE_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    private const NAME_ID_FORMAT_EMAIL = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

    /**
     * @param  array{entity_id:string,acs_url:string,metadata_url?:string,sign_authn_requests?:bool,want_assertions_signed?:bool,want_assertions_encrypted?:bool,certificate?:string}  $config
     */
    public function generate(array $config, ?CarbonImmutable $validUntil = null): string
    {
        /** @var string $entityIdRaw */
        $entityIdRaw = $config['entity_id'];
        $entityId = trim($entityIdRaw);
        if ($entityId === '') {
            throw new SamlMetadataException('SAML service provider entity ID is not configured.');
        }

        /** @var string $acsUrlRaw */
        $acsUrlRaw = $config['acs_url'];
        $acsUrl = trim($acsUrlRaw);
        if (! filter_var($acsUrl, FILTER_VALIDATE_URL)) {
            throw new SamlMetadataException('SAML service provider ACS URL is invalid.');
        }

        $certificate = $this->normalizeCertificate($config['certificate'] ?? null);
        $signRequests = isset($config['sign_authn_requests']) ? $config['sign_authn_requests'] : false;
        $wantAssertionsSigned = isset($config['want_assertions_signed']) ? $config['want_assertions_signed'] : true;
        $encryptAssertions = isset($config['want_assertions_encrypted']) ? $config['want_assertions_encrypted'] : false;

        $validUntil = ($validUntil ?? CarbonImmutable::now()->addDays(7))->utc();

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $entityDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:EntityDescriptor');
        $entityDescriptor->setAttribute('entityID', $entityId);
        $entityDescriptor->setAttribute('validUntil', $validUntil->toIso8601String());
        $document->appendChild($entityDescriptor);

        $spDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:SPSSODescriptor');
        $spDescriptor->setAttribute('protocolSupportEnumeration', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $spDescriptor->setAttribute('AuthnRequestsSigned', $signRequests ? 'true' : 'false');
        $spDescriptor->setAttribute('WantAssertionsSigned', $wantAssertionsSigned ? 'true' : 'false');
        $entityDescriptor->appendChild($spDescriptor);

        if ($certificate !== null) {
            $this->appendCertificate($document, $spDescriptor, $certificate, $encryptAssertions);
        }

        $nameIdFormat = $document->createElementNS(self::METADATA_NAMESPACE, 'md:NameIDFormat', self::NAME_ID_FORMAT_EMAIL);
        $spDescriptor->appendChild($nameIdFormat);

        $acs = $document->createElementNS(self::METADATA_NAMESPACE, 'md:AssertionConsumerService');
        $acs->setAttribute('Binding', self::PROTOCOL_BINDING);
        $acs->setAttribute('Location', $acsUrl);
        $acs->setAttribute('index', '0');
        $acs->setAttribute('isDefault', 'true');
        $spDescriptor->appendChild($acs);

        return (string) $document->saveXML();
    }

    private function normalizeCertificate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $body = preg_replace('/\s+/', '', str_ireplace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
            '',
            $normalized
        ));

        if ($body === null || $body === '') {
            throw new SamlMetadataException('SAML service provider certificate is invalid.');
        }

        return $body;
    }

    private function appendCertificate(DOMDocument $document, \DOMElement $descriptor, string $certificate, bool $includeEncryption): void
    {
        $keyDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:KeyDescriptor');
        $keyDescriptor->setAttribute('use', 'signing');
        $descriptor->appendChild($keyDescriptor);

        $keyInfo = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:KeyInfo');
        $keyDescriptor->appendChild($keyInfo);

        $x509Data = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Data');
        $keyInfo->appendChild($x509Data);

        $x509Certificate = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Certificate', $certificate);
        $x509Data->appendChild($x509Certificate);

        if (! $includeEncryption) {
            return;
        }

        $encryptionDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:KeyDescriptor');
        $encryptionDescriptor->setAttribute('use', 'encryption');
        $descriptor->appendChild($encryptionDescriptor);

        $encKeyInfo = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:KeyInfo');
        $encryptionDescriptor->appendChild($encKeyInfo);

        $encX509Data = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Data');
        $encKeyInfo->appendChild($encX509Data);

        $encCertificate = $document->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:X509Certificate', $certificate);
        $encX509Data->appendChild($encCertificate);

        $encMethodSymmetric = $document->createElementNS(self::METADATA_NAMESPACE, 'md:EncryptionMethod');
        $encMethodSymmetric->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#aes256-cbc');
        $encryptionDescriptor->appendChild($encMethodSymmetric);

        $encMethodAsymmetric = $document->createElementNS(self::METADATA_NAMESPACE, 'md:EncryptionMethod');
        $encMethodAsymmetric->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p');
        $encryptionDescriptor->appendChild($encMethodAsymmetric);
    }
}
