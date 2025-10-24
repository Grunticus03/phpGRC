<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Idp\Drivers\SamlIdpDriver;
use App\Http\Controllers\Controller;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\SamlAuthnRequestBuilder;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\Services\Auth\SamlStateTokenFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

final class SamlRedirectController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly SamlAuthnRequestBuilder $requestBuilder,
        private readonly SamlServiceProviderConfigResolver $spConfig,
        private readonly SamlStateTokenFactory $stateTokens,
        private readonly SamlIdpDriver $driver
    ) {}

    /**
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function redirect(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return $this->errorResponse(409, 'IDP_PERSISTENCE_DISABLED');
        }

        $providerValue = $request->query('provider');
        $providerIdentifier = is_string($providerValue) ? trim($providerValue) : '';
        if ($providerIdentifier === '' || strlen($providerIdentifier) > 160) {
            return $this->errorResponse(422, 'IDP_PROVIDER_INVALID', [
                'fields' => ['provider'],
            ]);
        }

        $provider = $this->providers->findByIdOrKey($providerIdentifier);
        if (! $provider instanceof IdpProvider) {
            return $this->errorResponse(404, 'IDP_PROVIDER_NOT_FOUND', [
                'provider' => $providerIdentifier,
            ]);
        }

        if (! $provider->enabled) {
            return $this->errorResponse(403, 'IDP_PROVIDER_DISABLED');
        }

        if (strtolower($provider->driver) !== 'saml') {
            return $this->errorResponse(422, 'IDP_PROVIDER_UNSUPPORTED');
        }

        /** @var array<string,mixed> $config */
        $config = (array) $provider->getAttribute('config');

        try {
            $normalizedConfig = $this->driver->normalizeConfig($config);
        } catch (\Throwable $e) {
            return $this->errorResponse(422, 'IDP_PROVIDER_INVALID', [
                'message' => $e->getMessage(),
            ]);
        }

        $entityIdValue = $normalizedConfig['entity_id'] ?? null;
        $ssoUrlValue = $normalizedConfig['sso_url'] ?? null;
        $certificateValue = $normalizedConfig['certificate'] ?? null;

        if (! is_string($entityIdValue) || ! is_string($ssoUrlValue) || ! is_string($certificateValue)) {
            return $this->errorResponse(422, 'IDP_PROVIDER_INVALID', [
                'message' => 'SAML provider configuration incomplete.',
            ]);
        }

        $idpConfig = [
            'entity_id' => $entityIdValue,
            'sso_url' => $ssoUrlValue,
            'certificate' => $certificateValue,
        ];

        $sp = $this->spConfig->resolve();
        $privateKey = $this->spConfig->privateKey();
        $passphrase = $this->spConfig->privateKeyPassphrase();

        $intended = $this->sanitizeReturnPath($request->query('return'));

        $requestId = '_'.str_replace('-', '', Str::uuid()->toString());
        $descriptor = $this->stateTokens->issue($provider, $requestId, $intended, $request);
        $relayState = $descriptor->token;
        if ($relayState === null) {
            throw new RuntimeException('Unable to issue SAML RelayState token.');
        }

        try {
            /** @var array{id:string,relay_state:string|null,url:string,destination:string,encoded_request:string,xml:string} $requestData */
            $requestData = $this->requestBuilder->build(
                $sp,
                $idpConfig,
                $relayState,
                $privateKey,
                $passphrase,
                $requestId
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(500, 'IDP_SAML_REQUEST_FAILED', [
                'message' => $e->getMessage(),
            ]);
        }

        $redirectUrl = $requestData['url'];

        if ($request->wantsJson()) {
            $stateMeta = [
                'token' => $relayState,
                'request_id' => $descriptor->requestId,
                'provider_id' => $descriptor->providerId,
                'provider_key' => $descriptor->providerKey,
                'intended' => $descriptor->intendedPath,
                'issued_at' => $descriptor->issuedAt,
            ];

            return response()->json([
                'ok' => true,
                'redirect' => $redirectUrl,
                'state' => $stateMeta,
            ]);
        }

        return redirect()->away($redirectUrl);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function errorResponse(int $status, string $code, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'meta' => $meta,
        ], $status);
    }

    private function sanitizeReturnPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 512) {
            return null;
        }

        if (! str_starts_with($trimmed, '/')) {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return null;
        }

        return $trimmed;
    }
}
