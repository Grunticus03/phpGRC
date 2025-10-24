<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds SAML AuthnRequest payloads for both health checks and interactive flows.
 */
final class SamlAuthnRequestBuilder
{
    public const INTERACTION_INTERACTIVE = 'interactive';

    public const INTERACTION_PASSIVE = 'passive';

    /**
     * @param  array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}  $sp
     * @param  array{entity_id:string,sso_url:string,certificate:string}  $idp
     * @param  string  $interaction  One of the SamlAuthnRequestBuilder::INTERACTION_* constants.
     * @return array{id:string,relay_state:string|null,url:string,destination:string,encoded_request:string,xml:string}
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function build(
        array $sp,
        array $idp,
        ?string $relayState,
        ?string $privateKey,
        ?string $privateKeyPassphrase,
        ?string $requestId = null,
        string $interaction = self::INTERACTION_INTERACTIVE
    ): array {
        $mode = $this->normalizeInteraction($interaction);

        $destination = $idp['sso_url'];
        if ($destination === '' || ! filter_var($destination, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('SAML SSO URL is invalid.');
        }

        $entityId = $sp['entity_id'];
        if (trim($entityId) === '') {
            throw new RuntimeException('SAML service provider entity ID is not configured.');
        }

        $acsUrl = $sp['acs_url'];
        if (trim($acsUrl) === '' || ! filter_var($acsUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('SAML service provider ACS URL is invalid.');
        }

        [$requestIdentifier, $encodedRequest, $requestXml] = $this->buildAuthnRequest(
            $entityId,
            $acsUrl,
            $destination,
            $requestId,
            $mode
        );

        $relay = $relayState !== null ? trim($relayState) : null;
        if ($relay === '') {
            $relay = null;
        }

        $queryParams = [
            'SAMLRequest' => $encodedRequest,
        ];

        if ($relay !== null) {
            $queryParams['RelayState'] = $relay;
        }

        if (! empty($sp['sign_authn_requests'])) {
            $sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $signatureInput = $this->buildRedirectSignaturePayload($encodedRequest, $relay, $sigAlg);
            $signature = $this->signRedirectPayload($signatureInput, $privateKey, $privateKeyPassphrase);
            $queryParams['SigAlg'] = $sigAlg;
            $queryParams['Signature'] = $signature;
        }

        $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        return [
            'id' => $requestIdentifier,
            'relay_state' => $relay,
            'url' => $this->appendQueryString($destination, $query),
            'destination' => $destination,
            'encoded_request' => $encodedRequest,
            'xml' => $requestXml,
        ];
    }

    /**
     * @param  string  $interaction  One of the SamlAuthnRequestBuilder::INTERACTION_* constants.
     * @return array{0:string,1:string,2:string}
     */
    private function buildAuthnRequest(
        string $entityId,
        string $acsUrl,
        string $destination,
        ?string $requestId = null,
        string $interaction = self::INTERACTION_INTERACTIVE
    ): array {
        $mode = $this->normalizeInteraction($interaction);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $identifier = $requestId ?? '_'.str_replace('-', '', Str::uuid()->toString());
        $issueInstant = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        $root = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:AuthnRequest');
        $root->setAttribute('ID', $identifier);
        $root->setAttribute('Version', '2.0');
        $root->setAttribute('IssueInstant', $issueInstant);
        $root->setAttribute('Destination', $destination);
        $root->setAttribute('ProtocolBinding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');
        $root->setAttribute('AssertionConsumerServiceURL', $acsUrl);
        $root->setAttribute('ForceAuthn', 'false');
        $root->setAttribute('IsPassive', $mode === self::INTERACTION_PASSIVE ? 'true' : 'false');
        $document->appendChild($root);

        $issuer = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:assertion', 'saml:Issuer', $entityId);
        $root->appendChild($issuer);

        $nameIdPolicy = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:NameIDPolicy');
        $nameIdPolicy->setAttribute('Format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified');
        $nameIdPolicy->setAttribute('AllowCreate', 'false');
        $root->appendChild($nameIdPolicy);

        $xml = $document->saveXML();
        if (! is_string($xml)) {
            throw new RuntimeException('Unable to serialize AuthnRequest XML.');
        }

        $trimmedXml = trim($xml);
        $deflated = gzdeflate($trimmedXml, 9);
        if ($deflated === false) {
            throw new RuntimeException('Failed to compress AuthnRequest payload.');
        }

        $encoded = base64_encode($deflated);

        return [$identifier, $encoded, $trimmedXml];
    }

    private function normalizeInteraction(string $interaction): string
    {
        $normalized = strtolower(trim($interaction));

        return match ($normalized) {
            self::INTERACTION_PASSIVE => self::INTERACTION_PASSIVE,
            default => self::INTERACTION_INTERACTIVE,
        };
    }

    private function buildRedirectSignaturePayload(string $encodedRequest, ?string $relayState, string $sigAlg): string
    {
        $parts = [
            'SAMLRequest='.rawurlencode($encodedRequest),
        ];

        if ($relayState !== null && $relayState !== '') {
            $parts[] = 'RelayState='.rawurlencode($relayState);
        }

        $parts[] = 'SigAlg='.rawurlencode($sigAlg);

        return implode('&', $parts);
    }

    private function signRedirectPayload(string $payload, ?string $privateKey, ?string $passphrase): string
    {
        if ($privateKey === null || trim($privateKey) === '') {
            throw new RuntimeException('SAML SP private key is required to sign AuthnRequests.');
        }

        $keyResource = openssl_pkey_get_private($privateKey, $passphrase ?? '');
        if ($keyResource === false) {
            throw new RuntimeException('Unable to load SAML SP private key for signing.');
        }

        $rawSignature = '';
        $success = false;

        try {
            $success = openssl_sign($payload, $rawSignature, $keyResource, OPENSSL_ALGO_SHA256);
        } finally {
            openssl_pkey_free($keyResource);
        }

        if ($success !== true) {
            throw new RuntimeException('SAML AuthnRequest signature generation returned an invalid value.');
        }

        if ($rawSignature === '') {
            throw new RuntimeException('SAML AuthnRequest signature generation returned an empty value.');
        }

        /** @var string $rawSignature */
        return base64_encode($rawSignature);
    }

    private function appendQueryString(string $base, string $query): string
    {
        if ($query === '') {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }
}
