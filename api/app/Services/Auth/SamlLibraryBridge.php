<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\SamlLibraryException;
use App\Exceptions\Auth\SamlMetadataException;
use App\Models\IdpProvider;
use App\ValueObjects\Auth\SamlLoginRequest;
use App\ValueObjects\Auth\SamlStateDescriptor;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Request;
use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Psr\Log\LoggerInterface;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class SamlLibraryBridge
{
    private const METADATA_NAMESPACE = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const SIGNATURE_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        private readonly SamlLibraryFactory $factory,
        private readonly SamlStateTokenFactory $stateTokens,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array{entity_id:string,sso_url:string,certificate:string,slo_url?:string,x509certMulti?:array<string,array<int,string>>}
     *
     * @SuppressWarnings("PMD.StaticAccess")
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function parseMetadata(string $metadataXml): array
    {
        $metadataXml = trim($metadataXml);
        if ($metadataXml === '') {
            throw new SamlMetadataException('SAML metadata must not be empty.');
        }

        try {
            /** @var array<string,mixed> $parsed */
            $parsed = IdPMetadataParser::parseXML(
                $metadataXml,
                null,
                null,
                Constants::BINDING_HTTP_REDIRECT,
                Constants::BINDING_HTTP_REDIRECT
            );
        } catch (\Throwable $e) {
            throw new SamlMetadataException('Unable to parse SAML metadata: '.$e->getMessage(), previous: $e);
        }

        /** @var array<string,mixed>|null $idp */
        $idp = $parsed['idp'] ?? null;
        if (! is_array($idp)) {
            throw new SamlMetadataException('SAML metadata missing IdP descriptor.');
        }

        $entityId = $this->stringValue($idp['entityId'] ?? null);
        if ($entityId === '') {
            throw new SamlMetadataException('SAML metadata missing EntityDescriptor::entityID.');
        }

        $sso = $idp['singleSignOnService'] ?? null;
        if (! is_array($sso) || ! isset($sso['url'])) {
            throw new SamlMetadataException('SAML metadata missing SingleSignOnService.');
        }

        $ssoUrl = $this->stringValue($sso['url']);
        if ($ssoUrl === '' || ! filter_var($ssoUrl, FILTER_VALIDATE_URL)) {
            throw new SamlMetadataException('SAML metadata SingleSignOnService URL is invalid.');
        }

        $certificate = $this->extractCertificate($idp);

        $result = [
            'entity_id' => $entityId,
            'sso_url' => $ssoUrl,
            'certificate' => $certificate,
        ];

        if (isset($idp['singleLogoutService']) && is_array($idp['singleLogoutService']) && isset($idp['singleLogoutService']['url'])) {
            $sloUrl = $this->stringValue($idp['singleLogoutService']['url']);
            if ($sloUrl !== '' && filter_var($sloUrl, FILTER_VALIDATE_URL)) {
                $result['slo_url'] = $sloUrl;
            }
        }

        if (isset($idp['x509certMulti']) && is_array($idp['x509certMulti'])) {
            $multi = [];
            foreach ($idp['x509certMulti'] as $key => $values) {
                if (! is_array($values)) {
                    continue;
                }

                $certificates = array_values(array_filter(
                    $values,
                    static fn ($candidate): bool => is_string($candidate) && trim($candidate) !== ''
                ));

                if ($certificates === []) {
                    continue;
                }

                /** @var list<string> $certificates */
                $multi[(string) $key] = array_map(static fn (string $certificate): string => trim($certificate), $certificates);
            }

            if ($multi !== []) {
                $result['x509certMulti'] = $multi;
            }
        }

        return $result;
    }

    /**
     * @param  array{entity_id?:string,sso_url?:string,certificate?:string,slo_url?:string}  $config
     */
    public function generateIdentityProviderMetadata(array $config, ?CarbonImmutable $validUntil = null): string
    {
        $entityId = $this->stringValue($config['entity_id'] ?? null);
        if ($entityId === '') {
            throw new SamlMetadataException('SAML entity ID is required.');
        }

        $ssoUrl = $this->stringValue($config['sso_url'] ?? null);
        if ($ssoUrl === '' || ! filter_var($ssoUrl, FILTER_VALIDATE_URL)) {
            throw new SamlMetadataException('SAML SSO URL must be a valid URL.');
        }

        $certificate = $config['certificate'] ?? null;
        if (! is_string($certificate) || trim($certificate) === '') {
            throw new SamlMetadataException('SAML signing certificate is required.');
        }

        $validUntil = ($validUntil ?? CarbonImmutable::now()->addDays(7))->utc();
        $certificateValue = $this->certificateToMetadataValue($certificate);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $entityDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:EntityDescriptor');
        $entityDescriptor->setAttribute('entityID', $entityId);
        $entityDescriptor->setAttribute('validUntil', $validUntil->toIso8601String());
        $entityDescriptor->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::SIGNATURE_NAMESPACE);
        $document->appendChild($entityDescriptor);

        $idpDescriptor = $document->createElementNS(self::METADATA_NAMESPACE, 'md:IDPSSODescriptor');
        $idpDescriptor->setAttribute('protocolSupportEnumeration', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $entityDescriptor->appendChild($idpDescriptor);

        $this->appendSigningCertificate($document, $idpDescriptor, $certificateValue);

        $sso = $document->createElementNS(self::METADATA_NAMESPACE, 'md:SingleSignOnService');
        $sso->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
        $sso->setAttribute('Location', $ssoUrl);
        $idpDescriptor->appendChild($sso);

        $sloUrl = $this->stringValue($config['slo_url'] ?? null);
        if ($sloUrl !== '' && filter_var($sloUrl, FILTER_VALIDATE_URL)) {
            $slo = $document->createElementNS(self::METADATA_NAMESPACE, 'md:SingleLogoutService');
            $slo->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
            $slo->setAttribute('Location', $sloUrl);
            $idpDescriptor->appendChild($slo);
        }

        return (string) $document->saveXML();
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $options
     *
     * @throws SamlError
     * @throws ValidationError
     *
     * @SuppressWarnings("PMD.StaticAccess")
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function createLoginRequest(
        IdpProvider $provider,
        array $config,
        Request $request,
        ?string $intendedPath = null,
        array $options = []
    ): SamlLoginRequest {
        $forceAuthn = (bool) ($options['force_authn'] ?? false);
        $isPassive = (bool) ($options['is_passive'] ?? false);
        $auth = $this->buildAuth($config);
        $settings = $auth->getSettings();

        /** @var AuthnRequest $authnRequest */
        $authnRequest = $auth->buildAuthnRequest(
            $settings,
            $forceAuthn,
            $isPassive,
            true
        );

        $requestId = $authnRequest->getId();
        if ($requestId === '') {
            throw new RuntimeException('SAML AuthnRequest missing ID.');
        }

        $descriptor = $this->issueState($provider, $requestId, $intendedPath, $request);
        $relayState = $descriptor->token;
        if ($relayState === null) {
            throw new RuntimeException('SAML state descriptor missing token.');
        }

        $samlRequest = $authnRequest->getRequest();
        if ($samlRequest === '') {
            throw new RuntimeException('SAML AuthnRequest payload is empty.');
        }
        $parameters = [
            'SAMLRequest' => $samlRequest,
            'RelayState' => $relayState,
        ];

        $security = $settings->getSecurityData();
        $signatureAlgorithm = XMLSecurityKey::RSA_SHA256;
        if (isset($security['signatureAlgorithm']) && is_string($security['signatureAlgorithm']) && $security['signatureAlgorithm'] !== '') {
            $signatureAlgorithm = $security['signatureAlgorithm'];
        }

        if (! empty($security['authnRequestsSigned'])) {
            $parameters['SigAlg'] = $signatureAlgorithm;
            $parameters['Signature'] = $auth->buildRequestSignature(
                $samlRequest,
                $relayState,
                $signatureAlgorithm
            );
        }

        $destination = $auth->getSSOurl();
        if ($destination === '') {
            throw new RuntimeException('SAML SSO URL is not configured.');
        }

        /** @var string $destination */
        $destination = $destination;

        /** @var string $redirectUrl */
        $redirectUrl = Utils::redirect($destination, $parameters, true);

        $xml = $authnRequest->getXML();
        if ($xml === '') {
            throw new RuntimeException('Failed to render SAML AuthnRequest XML.');
        }

        return new SamlLoginRequest(
            requestId: $requestId,
            destination: $destination,
            redirectUrl: $redirectUrl,
            encodedRequest: $samlRequest,
            xml: $xml,
            relayState: $relayState,
            stateDescriptor: $descriptor,
            parameters: $parameters
        );
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function createPassiveLoginRequest(IdpProvider $provider, array $config): SamlLoginRequest
    {
        $request = new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'phpGRC SAML Health/1.0',
        ]);

        return $this->createLoginRequest($provider, $config, $request, null, ['is_passive' => true]);
    }

    public function validateRelayState(string $relayState, Request $request): SamlStateDescriptor
    {
        return $this->stateTokens->validate($relayState, $request);
    }

    /**
     * @param  array<string,mixed>  $config
     *
     * @throws SamlError
     * @throws ValidationError
     * @throws SamlLibraryException
     */
    public function processResponse(
        IdpProvider $provider,
        array $config,
        Request $request,
        ?string $requestId = null
    ): OneLoginAuth {
        $auth = $this->buildAuth($config);

        $this->logger->debug('Processing SAML response.', [
            'provider' => $provider->key,
            'request_id' => $requestId,
            'relay_state' => $request->input('RelayState'),
        ]);

        $this->primeRequestGlobals($request);

        try {
            $auth->processResponse($requestId);
        } catch (\Throwable $e) {
            throw new SamlLibraryException('Failed to process SAML response: '.$e->getMessage(), previous: $e);
        }

        return $auth;
    }

    /**
     * @param  array<string,mixed>  $config
     *
     * @throws SamlError
     * @throws ValidationError
     *
     * @SuppressWarnings("PMD.StaticAccess")
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function createLogoutRequest(
        IdpProvider $provider,
        array $config,
        Request $request,
        ?string $returnTo = null
    ): SamlLoginRequest {
        $auth = $this->buildAuth($config);
        $settings = $auth->getSettings();

        /** @var LogoutRequest $logoutRequest */
        $logoutRequest = $auth->buildLogoutRequest($settings);
        $requestId = $logoutRequest->id;
        if ($requestId === '') {
            throw new RuntimeException('SAML logout request missing ID.');
        }

        $descriptor = $this->issueState($provider, $requestId, $returnTo, $request);
        $relayState = $descriptor->token;
        if ($relayState === null) {
            throw new RuntimeException('SAML state descriptor missing token.');
        }

        $samlRequest = $logoutRequest->getRequest();
        if ($samlRequest === '') {
            throw new RuntimeException('SAML logout request payload is empty.');
        }

        $parameters = [
            'SAMLRequest' => $samlRequest,
            'RelayState' => $relayState,
        ];

        $security = $settings->getSecurityData();
        $signatureAlgorithm = XMLSecurityKey::RSA_SHA256;
        if (isset($security['signatureAlgorithm']) && is_string($security['signatureAlgorithm']) && $security['signatureAlgorithm'] !== '') {
            $signatureAlgorithm = $security['signatureAlgorithm'];
        }

        if (! empty($security['logoutRequestSigned'])) {
            $parameters['SigAlg'] = $signatureAlgorithm;
            $parameters['Signature'] = $auth->buildRequestSignature(
                $samlRequest,
                $relayState,
                $signatureAlgorithm
            );
        }

        $destination = $auth->getSLOurl();
        if ($destination === '') {
            throw new RuntimeException('SAML SLO URL is not configured.');
        }

        /** @var string $destination */
        $destination = $destination;

        /** @var string $redirectUrl */
        $redirectUrl = Utils::redirect($destination, $parameters, true);

        $xml = $logoutRequest->getXML();
        if ($xml === '') {
            throw new RuntimeException('Failed to render SAML logout request XML.');
        }

        return new SamlLoginRequest(
            requestId: $requestId,
            destination: $destination,
            redirectUrl: $redirectUrl,
            encodedRequest: $samlRequest,
            xml: $xml,
            relayState: $relayState,
            stateDescriptor: $descriptor,
            parameters: $parameters
        );
    }

    /**
     * @param  array<string,mixed>  $config
     *
     * @throws SamlError
     * @throws ValidationError
     */
    public function processLogoutResponse(
        array $config,
        string $logoutResponse,
        ?string $relayState = null
    ): LogoutResponse {
        $auth = $this->buildAuth($config);
        $settings = $auth->getSettings();

        $this->logger->debug('Processing SAML logout response.', [
            'relay_state' => $relayState,
        ]);

        try {
            /** @var LogoutResponse $response */
            $response = $auth->buildLogoutResponse($settings, $logoutResponse);
        } catch (ValidationError|SamlError $e) {
            throw new SamlLibraryException('Failed to process SAML logout response: '.$e->getMessage(), previous: $e);
        }

        if (! $response->isValid($relayState)) {
            throw new SamlLibraryException('SAML logout response failed validation.');
        }

        return $response;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildAuth(array $config): OneLoginAuth
    {
        return $this->factory->make($config);
    }

    private function primeRequestGlobals(Request $request): void
    {
        unset($_POST['SAMLResponse'], $_POST['RelayState']);

        /** @var mixed $responseValue */
        $responseValue = $request->input('SAMLResponse', $request->input('saml_response'));
        if (is_string($responseValue)) {
            $trimmedResponse = trim($responseValue);
            if ($trimmedResponse !== '') {
                $_POST['SAMLResponse'] = $trimmedResponse;
            }
        }

        /** @var mixed $relayInput */
        $relayInput = $request->input('RelayState', $request->input('relay_state'));
        if (is_string($relayInput)) {
            $trimmedRelay = trim($relayInput);
            if ($trimmedRelay !== '') {
                $_POST['RelayState'] = $trimmedRelay;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $idp
     */
    private function extractCertificate(array $idp): string
    {
        $certificate = null;

        if (isset($idp['x509cert']) && is_string($idp['x509cert'])) {
            $certificate = $idp['x509cert'];
        } elseif (isset($idp['x509certMulti']) && is_array($idp['x509certMulti'])) {
            $multi = $idp['x509certMulti'];
            foreach (['signing', 'encryption'] as $slot) {
                $bucket = $multi[$slot] ?? null;
                if (! is_array($bucket)) {
                    continue;
                }

                /** @psalm-suppress MixedAssignment */
                foreach ($bucket as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $certificate = $candidate;
                        break 2;
                    }
                }
            }
        }

        if (! is_string($certificate) || trim($certificate) === '') {
            throw new SamlMetadataException('SAML metadata missing X509Certificate.');
        }

        return $this->formatCertificate($certificate);
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

    private function stringValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function issueState(IdpProvider $provider, string $requestId, ?string $intendedPath, Request $request): SamlStateDescriptor
    {
        return $this->stateTokens->issue($provider, $requestId, $intendedPath, $request);
    }

    private function appendSigningCertificate(DOMDocument $document, DOMElement $descriptor, string $certificate): void
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
}
