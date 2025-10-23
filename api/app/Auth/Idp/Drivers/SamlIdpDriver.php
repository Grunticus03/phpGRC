<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Exceptions\Auth\SamlMetadataException;
use App\Services\Auth\SamlMetadataService;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class SamlIdpDriver extends AbstractIdpDriver
{
    public function __construct(
        private readonly SamlMetadataService $metadata,
        private readonly SamlServiceProviderConfigResolver $spConfig
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
        $ssoUrl = $this->requireUrl($config, 'sso_url', $errors, 'SSO URL must be a valid URL.');
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
     *
     * @SuppressWarnings("PMD.StaticAccess")
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

        $sp = $this->serviceProviderConfig();
        if ($sp['entity_id'] === '') {
            return IdpHealthCheckResult::failed('Service provider entity ID is not configured.', [
                'sp' => $sp,
            ]);
        }

        if (! filter_var($sp['acs_url'], FILTER_VALIDATE_URL)) {
            return IdpHealthCheckResult::failed('Service provider ACS URL is invalid.', [
                'sp' => $sp,
            ]);
        }

        return $this->performRemoteHealthCheck($normalized, $sp);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}  $sp
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function performRemoteHealthCheck(array $config, array $sp): IdpHealthCheckResult
    {
        $destination = $config['sso_url'] ?? '';
        if (! is_string($destination) || $destination === '' || ! filter_var($destination, FILTER_VALIDATE_URL)) {
            return IdpHealthCheckResult::failed('SAML SSO URL is invalid.', [
                'sp' => $sp,
            ]);
        }

        try {
            /** @var array{id:string,relay_state:string,url:string,destination:string,encoded_request:string,xml:string} $requestData */
            $requestData = $this->buildHealthRequestData($sp, $destination);
        } catch (\Throwable $e) {
            return IdpHealthCheckResult::failed('Failed to prepare SAML AuthnRequest.', [
                'sp' => $sp,
                'error' => $this->exceptionMessage($e),
            ]);
        }

        try {
            $response = $this->sendHealthRequest($requestData['url']);
        } catch (ConnectionException|RequestException $e) {
            return IdpHealthCheckResult::failed('Unable to contact SAML SSO endpoint.', [
                'sp' => $sp,
                'request' => $this->requestMeta($requestData),
                'error' => $this->exceptionMessage($e),
            ]);
        } catch (\Throwable $e) {
            return IdpHealthCheckResult::failed('Unexpected error during SAML health check.', [
                'sp' => $sp,
                'request' => $this->requestMeta($requestData),
                'error' => $this->exceptionMessage($e),
            ]);
        }

        $details = $this->assembleResponseDetails($requestData, $response, $sp);

        return $this->interpretHealthResponse($response, $details);
    }

    /**
     * @param  array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}  $sp
     * @return array{id:string,relay_state:string,url:string,destination:string,encoded_request:string,xml:string}
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function buildHealthRequestData(array $sp, string $destination): array
    {
        /** @var string $entityId */
        $entityId = $sp['entity_id'];
        /** @var string $acsUrl */
        $acsUrl = $sp['acs_url'];

        [$requestId, $encodedRequest, $requestXml] = $this->buildAuthnRequest(
            $entityId,
            $acsUrl,
            $destination
        );

        $relayState = 'phpgrc-health-'.Str::lower(Str::random(12));
        $queryParams = [
            'SAMLRequest' => $encodedRequest,
            'RelayState' => $relayState,
        ];

        if ($sp['sign_authn_requests']) {
            $sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $signatureInput = $this->buildRedirectSignaturePayload($encodedRequest, $relayState, $sigAlg);
            $signature = $this->signRedirectPayload($signatureInput);
            $queryParams['SigAlg'] = $sigAlg;
            $queryParams['Signature'] = $signature;
        }

        $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        return [
            'id' => $requestId,
            'relay_state' => $relayState,
            'url' => $this->appendQueryString($destination, $query),
            'destination' => $destination,
            'encoded_request' => $encodedRequest,
            'xml' => $requestXml,
        ];
    }

    private function sendHealthRequest(string $url): Response
    {
        return Http::withHeaders([
            'User-Agent' => 'phpGRC SAML Health/1.0',
            'Accept' => 'text/html,application/xml;q=0.9,*/*;q=0.8',
        ])
            ->timeout(10)
            ->withoutRedirecting()
            ->get($url);
    }

    /**
     * @param  array{id:string,relay_state:string,url:string,destination:string,encoded_request:string,xml:string}  $requestData
     * @return array<string,mixed>
     */
    private function requestMeta(array $requestData): array
    {
        return [
            'id' => $requestData['id'],
            'relay_state' => $requestData['relay_state'],
            'url' => $requestData['url'],
            'destination' => $requestData['destination'],
        ];
    }

    /**
     * @param  array{id:string,relay_state:string,url:string,destination:string,encoded_request:string,xml:string}  $requestData
     * @param  array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}  $sp
     * @return array<string,mixed>
     */
    private function assembleResponseDetails(array $requestData, Response $response, array $sp): array
    {
        $status = $response->status();
        $rawBody = $response->body();
        $bodyPreview = $this->extractBodyPreview($rawBody);

        /** @var array<string, array<int, string>|string> $rawHeaders */
        $rawHeaders = $response->headers();
        $headers = $this->normalizeHeaders($rawHeaders);

        $responsePayload = [
            'status' => $status,
            'headers' => $headers,
            'body_preview' => $bodyPreview,
        ];

        $location = $headers['Location'] ?? $headers['location'] ?? null;
        if (is_string($location) && $location !== '') {
            $responsePayload['location'] = $location;
        }

        $forwardedStatus = $this->extractForwardedStatus($headers);
        if ($forwardedStatus !== null) {
            $responsePayload['forwarded_status'] = $forwardedStatus;
        }

        $adfsErrorDetail = $this->extractAdfsErrorDetail($rawBody);
        if ($adfsErrorDetail !== null) {
            $responsePayload['adfs_error_detail'] = $adfsErrorDetail;
        }

        /** @var array<string,mixed> $details */
        $details = [
            'sp' => $sp,
            'request' => [
                'id' => $requestData['id'],
                'relay_state' => $requestData['relay_state'],
                'url' => $requestData['url'],
                'destination' => $requestData['destination'],
                'encoded_length' => strlen($requestData['encoded_request']),
                'xml_preview' => $this->extractBodyPreview($requestData['xml'], 256),
            ],
            'response' => $responsePayload,
        ];

        return $details;
    }

    /**
     * @param  array<string,mixed>  $details
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function interpretHealthResponse(Response $response, array $details): IdpHealthCheckResult
    {
        $status = $response->status();
        /** @var mixed $responseValue */
        $responseValue = $details['response'] ?? [];
        if (! is_array($responseValue)) {
            $responseValue = [];
        }
        /** @var array<string,mixed> $responseDetails */
        $responseDetails = $responseValue;

        /** @var mixed $bodyPreviewRaw */
        $bodyPreviewRaw = $responseDetails['body_preview'] ?? '';
        $bodyPreview = is_string($bodyPreviewRaw) ? $bodyPreviewRaw : '';
        $forwardedStatus = null;
        /** @var mixed $forwardedRaw */
        $forwardedRaw = $responseDetails['forwarded_status'] ?? null;
        if (is_int($forwardedRaw)) {
            $forwardedStatus = $forwardedRaw;
        } elseif (is_numeric($forwardedRaw)) {
            $forwardedStatus = (int) $forwardedRaw;
        }

        /** @var mixed $adfsErrorRaw */
        $adfsErrorRaw = $responseDetails['adfs_error_detail'] ?? null;
        $adfsErrorDetail = is_string($adfsErrorRaw) ? $adfsErrorRaw : null;

        $evaluation = $this->evaluateHealthStatus($status, $forwardedStatus, $bodyPreview, $adfsErrorDetail);

        if ($evaluation['result'] === 'error') {
            return IdpHealthCheckResult::failed($evaluation['message'], $details);
        }

        return IdpHealthCheckResult::healthy($evaluation['message'], $details);
    }

    /**
     * @return array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}
     */
    private function serviceProviderConfig(): array
    {
        return $this->spConfig->resolve();
    }

    /**
     * @return array{0:string,1:string,2:string}
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function buildAuthnRequest(string $entityId, string $acsUrl, string $destination): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $requestId = '_'.str_replace('-', '', Str::uuid()->toString());
        $issueInstant = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        $root = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:AuthnRequest');
        $root->setAttribute('ID', $requestId);
        $root->setAttribute('Version', '2.0');
        $root->setAttribute('IssueInstant', $issueInstant);
        $root->setAttribute('Destination', $destination);
        $root->setAttribute('ProtocolBinding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');
        $root->setAttribute('AssertionConsumerServiceURL', $acsUrl);
        $root->setAttribute('ForceAuthn', 'false');
        $root->setAttribute('IsPassive', 'true');
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

        return [$requestId, $encoded, $trimmedXml];
    }

    private function buildRedirectSignaturePayload(string $encodedRequest, string $relayState, string $sigAlg): string
    {
        $parts = [
            'SAMLRequest='.rawurlencode($encodedRequest),
        ];

        if ($relayState !== '') {
            $parts[] = 'RelayState='.rawurlencode($relayState);
        }

        $parts[] = 'SigAlg='.rawurlencode($sigAlg);

        return implode('&', $parts);
    }

    private function signRedirectPayload(string $payload): string
    {
        $privateKey = $this->spConfig->privateKey();
        if ($privateKey === null) {
            throw new RuntimeException('SAML SP private key is required to sign AuthnRequests.');
        }

        $passphrase = $this->spConfig->privateKeyPassphrase();
        $key = openssl_pkey_get_private($privateKey, $passphrase ?? '');
        if ($key === false) {
            throw new RuntimeException('Unable to load SAML SP private key for signing.');
        }

        $rawSignature = '';
        $success = false;
        try {
            $success = openssl_sign($payload, $rawSignature, $key, OPENSSL_ALGO_SHA256);
        } finally {
            openssl_pkey_free($key);
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

    private function exceptionMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return sprintf('%s thrown with no message.', $e::class);
        }

        return sprintf('%s: %s', $e::class, $message);
    }

    private function extractBodyPreview(string $body, int $limit = 512): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, $limit);
        }

        return substr($trimmed, 0, $limit);
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                /** @var array<int,string> $value */
                $normalized[$key] = implode(', ', array_map(static fn (string $v): string => trim($v), $value));

                continue;
            }

            $normalized[$key] = trim($value);
        }

        return $normalized;
    }

    private function containsAdfsRelyingPartyError(string $bodyPreview): bool
    {
        if ($bodyPreview === '') {
            return false;
        }

        $normalized = strtolower($bodyPreview);

        return str_contains($normalized, 'msis7012')
            || str_contains($normalized, 'relying party trust')
            || str_contains($normalized, 'msis9605');
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function extractForwardedStatus(array $headers): ?int
    {
        $candidate = $headers['X-MS-Forwarded-Status-Code'] ?? $headers['x-ms-forwarded-status-code'] ?? null;
        if (! is_string($candidate)) {
            return null;
        }

        $trimmed = trim($candidate);
        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        $value = (int) $trimmed;
        if ($value < 100) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{result:'ok'|'error',message:string}
     */
    private function evaluateHealthStatus(int $status, ?int $forwardedStatus, string $bodyPreview, ?string $adfsErrorDetail): array
    {
        if ($status >= 400) {
            return [
                'result' => 'error',
                'message' => sprintf('IdP responded with HTTP %d.', $status),
            ];
        }

        if ($forwardedStatus !== null && $forwardedStatus >= 400) {
            return [
                'result' => 'error',
                'message' => sprintf('IdP forwarded HTTP %d. Verify relying party configuration.', $forwardedStatus),
            ];
        }

        if ($adfsErrorDetail !== null) {
            return [
                'result' => 'error',
                'message' => sprintf('IdP returned an error page: %s', $adfsErrorDetail),
            ];
        }

        if ($this->containsAdfsRelyingPartyError($bodyPreview)) {
            return [
                'result' => 'error',
                'message' => 'IdP rejected the AuthnRequest (relying party trust not configured).',
            ];
        }

        if ($status >= 300) {
            return [
                'result' => 'ok',
                'message' => 'IdP accepted the AuthnRequest and issued a redirect.',
            ];
        }

        return [
            'result' => 'ok',
            'message' => 'IdP responded with HTTP 200. Review the response body to confirm the login page is displayed.',
        ];
    }

    private function extractAdfsErrorDetail(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = strip_tags($decoded);
        $plain = preg_replace('/\s+/', ' ', $plain) ?? '';
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        if (preg_match('/Error Details?:\s*([^\s].*?)(?:\s{2,}|$)/i', $plain, $match)) {
            return $this->summarizeErrorDetail($match[1]);
        }

        if (preg_match('/(MSIS\d{4}[^.]*\.)/i', $plain, $match)) {
            return $this->summarizeErrorDetail($match[1]);
        }

        return null;
    }

    private function summarizeErrorDetail(string $detail): string
    {
        $normalized = trim($detail);
        if ($normalized === '') {
            return 'Unknown error returned by IdP.';
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = function_exists('mb_substr')
            ? mb_substr($normalized, 0, 240)
            : substr($normalized, 0, 240);

        return rtrim($normalized, ' .').'.';
    }
}
