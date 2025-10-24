<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Exceptions\Auth\SamlMetadataException;
use App\Models\IdpProvider;
use App\Services\Auth\SamlLibraryBridge;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\ValueObjects\Auth\SamlLoginRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class SamlIdpDriver extends AbstractIdpDriver
{
    public function __construct(
        private readonly SamlLibraryBridge $bridge,
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
                $parsed = $this->bridge->parseMetadata($metadataXml);
                $config = array_merge($config, $parsed);
            } catch (SamlMetadataException $e) {
                $this->addError($errors, 'config.metadata', $e->getMessage());
            }
        }
        unset($config['metadata'], $config['metadata_xml']);

        $entityId = $this->requireString($config, 'entity_id', $errors, 'Entity ID is required.');
        $ssoUrl = $this->requireUrl($config, 'sso_url', $errors, 'SSO URL must be a valid URL.');
        $certificate = $this->requireString($config, 'certificate', $errors, 'Signing certificate is required.');

        if ($certificate !== '' && ! str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $this->addError($errors, 'config.certificate', 'Certificate must be a PEM encoded block.');
        }

        $config['entity_id'] = $entityId;
        $config['sso_url'] = $ssoUrl;
        $config['certificate'] = $certificate;

        if (isset($config['slo_url']) && is_string($config['slo_url'])) {
            $sloUrl = trim($config['slo_url']);
            if ($sloUrl === '') {
                unset($config['slo_url']);
            }

            $isValidSlo = $sloUrl !== '' && filter_var($sloUrl, FILTER_VALIDATE_URL);
            if ($sloUrl !== '' && ! $isValidSlo) {
                $this->addError($errors, 'config.slo_url', 'SLO URL must be a valid URL.');
            }

            if ($isValidSlo) {
                $config['slo_url'] = $sloUrl;
            }
        }

        $this->throwIfErrors($errors);

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

        $provider = $this->resolveProviderContext($config);

        try {
            $requestContext = $this->bridge->createPassiveLoginRequest($provider, $config);
        } catch (\Throwable $e) {
            return IdpHealthCheckResult::failed('Failed to prepare SAML AuthnRequest.', [
                'sp' => $sp,
                'provider' => [
                    'id' => $provider->id,
                    'key' => $provider->key,
                ],
                'error' => $this->exceptionMessage($e),
            ]);
        }

        try {
            $response = $this->sendHealthRequest($requestContext->redirectUrl);
        } catch (ConnectionException|RequestException $e) {
            return IdpHealthCheckResult::failed('Unable to contact SAML SSO endpoint.', [
                'sp' => $sp,
                'request' => $this->requestMeta($requestContext),
                'error' => $this->exceptionMessage($e),
            ]);
        } catch (\Throwable $e) {
            return IdpHealthCheckResult::failed('Unexpected error during SAML health check.', [
                'sp' => $sp,
                'request' => $this->requestMeta($requestContext),
                'error' => $this->exceptionMessage($e),
            ]);
        }

        $details = $this->assembleResponseDetails($requestContext, $response, $sp);

        return $this->interpretHealthResponse($response, $details);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function resolveProviderContext(array &$config): IdpProvider
    {
        /** @var array<string,mixed>|null $meta */
        $meta = $config['_provider'] ?? null;
        unset($config['_provider']);

        $id = null;
        $key = null;

        if (is_array($meta)) {
            /** @var mixed $idValue */
            $idValue = $meta['id'] ?? $meta['provider_id'] ?? null;
            if (is_string($idValue) && trim($idValue) !== '') {
                $id = trim($idValue);
            }

            /** @var mixed $keyValue */
            $keyValue = $meta['key'] ?? $meta['provider_key'] ?? null;
            if (is_string($keyValue) && trim($keyValue) !== '') {
                $key = trim($keyValue);
            }
        }

        $id ??= 'saml-health-preview';
        $key ??= 'saml-health-preview';

        $provider = new IdpProvider;
        $provider->setAttribute('id', $id);
        $provider->setAttribute('key', $key);
        $provider->setAttribute('driver', 'saml');

        return $provider;
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
     * @return array{
     *     id: string,
     *     relay_state: string|null,
     *     url: string,
     *     destination: string,
     *     signed: bool,
     *     signature_algorithm: string|null
     * }
     */
    private function requestMeta(SamlLoginRequest $request): array
    {
        return array_merge([
            'id' => $request->requestId,
            'relay_state' => $request->relayState,
            'url' => $request->redirectUrl,
            'destination' => $request->destination,
        ], $this->requestSignatureFlags($request));
    }

    /**
     * @param  array{entity_id:string,acs_url:string,metadata_url:string,sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool,certificate?:string}  $sp
     * @return array<string,mixed>
     */
    private function assembleResponseDetails(SamlLoginRequest $request, Response $response, array $sp): array
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

        $requestDetails = array_merge(
            $this->requestMeta($request),
            [
                'encoded_length' => strlen($request->encodedRequest),
                'xml_preview' => $this->extractBodyPreview($request->xml, 256),
            ]
        );

        /** @var array<string,mixed> $details */
        $details = [
            'sp' => $sp,
            'request' => $requestDetails,
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
     * @return array{signed:bool,signature_algorithm:?string}
     */
    private function requestSignatureFlags(SamlLoginRequest $request): array
    {
        /** @var array<string,mixed> $parameters */
        $parameters = $request->parameters;

        $signatureRaw = $parameters['Signature'] ?? null;
        $signed = is_string($signatureRaw) && trim($signatureRaw) !== '';

        if (! $signed) {
            return [
                'signed' => false,
                'signature_algorithm' => null,
            ];
        }

        $algorithm = null;
        if (isset($parameters['SigAlg']) && is_string($parameters['SigAlg'])) {
            $trimmed = trim($parameters['SigAlg']);
            if ($trimmed !== '') {
                $algorithm = $trimmed;
            }
        }

        return [
            'signed' => true,
            'signature_algorithm' => $algorithm,
        ];
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
