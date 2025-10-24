<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Exceptions\Auth\SamlMetadataException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\IdpProviderStoreRequest;
use App\Http\Requests\Auth\IdpProviderUpdateRequest;
use App\Http\Requests\Auth\SamlMetadataPreviewRequest;
use App\Http\Requests\Auth\SamlMetadataRequest;
use App\Http\Requests\Auth\SamlServiceProviderUpdateRequest;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\SamlLibraryBridge;
use App\Services\Auth\SamlServiceProviderConfigResolver;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class IdpProviderController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $service,
        private readonly SamlLibraryBridge $saml,
        private readonly SamlServiceProviderConfigResolver $samlSpConfig,
        private readonly SettingsService $settings
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'meta' => [
                    'total' => 0,
                    'enabled' => 0,
                ],
                'note' => 'stub-only',
            ], 200);
        }

        $providers = $this->service->all();
        $items = array_map(fn (IdpProvider $provider): array => $this->formatProvider($provider), $providers->all());

        $enabledCount = $providers->filter(fn (IdpProvider $provider): bool => $provider->enabled)->count();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'meta' => [
                'total' => $providers->count(),
                'enabled' => $enabledCount,
            ],
        ], 200);
    }

    public function samlServiceProvider(): JsonResponse
    {
        $config = $this->samlSpConfig->resolve();

        return response()->json([
            'ok' => true,
            'sp' => $config,
        ], 200);
    }

    public function updateSamlServiceProvider(SamlServiceProviderUpdateRequest $request): JsonResponse
    {
        if (! $this->settings->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'sp' => $this->samlSpConfig->resolve(),
            ], 202);
        }

        /** @var array{sign_authn_requests:bool,want_assertions_signed:bool,want_assertions_encrypted:bool} $payload */
        $payload = $request->validated();

        $accepted = [
            'auth' => [
                'saml' => [
                    'sp' => [
                        'sign_authn_requests' => $payload['sign_authn_requests'],
                        'want_assertions_signed' => $payload['want_assertions_signed'],
                        'want_assertions_encrypted' => $payload['want_assertions_encrypted'],
                    ],
                ],
            ],
        ];

        $uid = auth()->id();
        $actorId = is_int($uid)
            ? $uid
            : (is_string($uid) && ctype_digit($uid) ? (int) $uid : null);

        $result = $this->settings->apply(
            accepted: $accepted,
            actorId: $actorId,
            context: ['origin' => 'auth.saml.sp']
        );

        return response()->json([
            'ok' => true,
            'sp' => $this->samlSpConfig->resolve(),
            'changes' => $result['changes'],
        ], 200);
    }

    public function store(IdpProviderStoreRequest $request): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'provider' => $request->validated(),
            ], 202);
        }

        /** @var array<string,mixed> $payload */
        $payload = $request->validated();
        try {
            $provider = $this->service->create($payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'provider' => $this->formatProvider($provider),
        ], 201);
    }

    public function show(string $provider): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PERSISTENCE_DISABLED',
            ], 409);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        return response()->json([
            'ok' => true,
            'provider' => $this->formatProvider($model),
        ], 200);
    }

    public function update(IdpProviderUpdateRequest $request, string $provider): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'provider' => array_merge(['id' => $provider], $request->validated()),
            ], 202);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        /** @var array<string,mixed> $payload */
        $payload = $request->validated();
        try {
            $updated = $this->service->update($model, $payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'provider' => $this->formatProvider($updated),
        ], 200);
    }

    public function destroy(string $provider): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'deleted' => $provider,
            ], 202);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        try {
            $this->service->delete($model);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'deleted' => $model->key,
        ], 200);
    }

    public function health(string $provider): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
            ], 202);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        try {
            $result = $this->service->checkHealth($model);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => $result->status === IdpHealthCheckResult::STATUS_OK,
            'status' => $result->status,
            'message' => $result->message,
            'checked_at' => $result->checkedAt->toIso8601String(),
            'details' => $result->details,
            'provider' => $this->formatProvider($model),
        ], 200);
    }

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function previewSamlMetadata(SamlMetadataPreviewRequest $request): JsonResponse
    {
        /** @var array{metadata?:string,url?:string} $payload */
        $payload = $request->validated();

        $metadata = $payload['metadata'] ?? null;
        if ($metadata === null) {
            $url = $payload['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                throw ValidationException::withMessages([
                    'metadata' => ['SAML metadata is required.'],
                ]);
            }

            $metadata = $this->downloadSamlMetadata($url);
        }

        try {
            $config = $this->saml->parseMetadata($metadata);
        } catch (SamlMetadataException $e) {
            throw ValidationException::withMessages([
                'metadata' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'ok' => true,
            'config' => $config,
        ], 200);
    }

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function downloadSamlMetadata(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'url' => ['Provide a metadata URL to download.'],
            ]);
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'url' => ['Metadata URL must use http:// or https://.'],
            ]);
        }

        try {
            $response = Http::accept('application/samlmetadata+xml, application/xml, text/xml, */*')
                ->timeout(10)
                ->withHeaders([
                    'User-Agent' => 'phpGRC Metadata Preview/1.0',
                ])
                ->get($trimmed);
        } catch (ConnectionException|RequestException $e) {
            throw ValidationException::withMessages([
                'url' => [$this->formatMetadataDownloadError($e->getMessage())],
            ]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'url' => [$this->formatMetadataDownloadError($e->getMessage())],
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => [sprintf('Failed to download metadata. HTTP %d.', $response->status())],
            ]);
        }

        $body = trim($response->body());
        if ($body === '') {
            throw ValidationException::withMessages([
                'url' => ['The downloaded metadata document was empty.'],
            ]);
        }

        return $body;
    }

    private function formatMetadataDownloadError(string $detail): string
    {
        $normalized = trim($detail);
        if ($normalized === '') {
            return 'Failed to download metadata. Verify the URL and connectivity.';
        }

        return sprintf('Failed to download metadata. %s', $normalized);
    }

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function importSamlMetadata(SamlMetadataRequest $request, string $provider): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
            ], 202);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        if (strtolower($model->driver) !== 'saml') {
            return $this->invalidArgumentResponse('Metadata import is only supported for SAML providers.');
        }

        /** @var array{metadata:string} $payload */
        $payload = $request->validated();

        try {
            $config = $this->saml->parseMetadata($payload['metadata']);
        } catch (SamlMetadataException $e) {
            throw ValidationException::withMessages([
                'metadata' => [$e->getMessage()],
            ]);
        }

        $meta = $this->mergeSamlMeta($model, $payload['metadata']);

        try {
            $updated = $this->service->update($model, [
                'config' => $config,
                'meta' => $meta,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'provider' => $this->formatProvider($updated),
        ], 200);
    }

    public function exportSamlMetadata(string $provider): Response
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
            ], 202);
        }

        $model = $this->service->findByIdOrKey($provider);
        if ($model === null) {
            return $this->notFoundResponse($provider);
        }

        if (strtolower($model->driver) !== 'saml') {
            return $this->invalidArgumentResponse('Metadata export is only supported for SAML providers.');
        }

        /** @var array<string,mixed> $providerConfig */
        $providerConfig = $model->config;
        $entityId = $providerConfig['entity_id'] ?? null;
        $ssoUrl = $providerConfig['sso_url'] ?? null;
        $certificate = $providerConfig['certificate'] ?? null;

        if (! is_string($entityId) || ! is_string($ssoUrl) || ! is_string($certificate)) {
            return $this->invalidArgumentResponse('SAML provider configuration is incomplete.');
        }

        try {
            $metadata = $this->saml->generateIdentityProviderMetadata([
                'entity_id' => $entityId,
                'sso_url' => $ssoUrl,
                'certificate' => $certificate,
            ]);
        } catch (SamlMetadataException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response($metadata, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=\"saml-metadata.xml\"',
        ]);
    }

    private function invalidArgumentResponse(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'IDP_PROVIDER_INVALID',
            'message' => $message,
        ], 422);
    }

    private function notFoundResponse(string $provider): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'IDP_PROVIDER_NOT_FOUND',
            'provider' => $provider,
        ], 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatProvider(IdpProvider $provider): array
    {
        $config = $this->toArray($provider->getAttribute('config'));
        $meta = $this->toArray($provider->getAttribute('meta'));
        $lastHealth = $provider->last_health_at;
        $createdAt = $provider->created_at;
        $updatedAt = $provider->updated_at;

        return [
            'id' => $provider->id,
            'key' => $provider->key,
            'name' => $provider->name,
            'driver' => $provider->driver,
            'enabled' => $provider->enabled,
            'evaluation_order' => $provider->evaluation_order,
            'config' => $config,
            'meta' => $meta === [] ? new stdClass : $meta,
            'reference' => $this->extractReference($meta, $provider->evaluation_order),
            'last_health_at' => $lastHealth instanceof CarbonInterface ? $lastHealth->toAtomString() : null,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toAtomString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toAtomString() : null,
        ];
    }

    /**
     * @return array<array-key,mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \ArrayObject) {
            /** @var array<string,mixed> $copy */
            $copy = $value->getArrayCopy();

            return $copy;
        }

        if ($value instanceof \Traversable) {
            /** @var array<string,mixed> $converted */
            $converted = iterator_to_array($value);

            return $converted;
        }

        if (is_object($value)) {
            /** @var array<string,mixed> $converted */
            $converted = (array) $value;

            return $converted;
        }

        return [$value];
    }

    /**
     * @return array<int|string,mixed>
     */
    private function mergeSamlMeta(IdpProvider $provider, string $metadataXml): array
    {
        $meta = $this->toArray($provider->getAttribute('meta'));

        if ($meta !== [] && array_is_list($meta)) {
            $meta = [];
        }

        $current = [];
        if (array_key_exists('saml', $meta) && is_array($meta['saml'])) {
            /** @var array<string,mixed> $existing */
            $existing = $meta['saml'];
            $current = $existing;
        }

        $current['metadata_imported_at'] = CarbonImmutable::now()->toAtomString();
        $current['metadata_sha256'] = hash('sha256', $metadataXml);

        $meta['saml'] = $current;

        return $meta;
    }

    /**
     * @param  array<int|string,mixed>  $meta
     */
    private function extractReference(array $meta, int $fallback): int
    {
        /** @var mixed $raw */
        $raw = $meta['reference'] ?? null;
        if (is_int($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return max(1, $fallback);
    }
}
