<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\IdpProviderStoreRequest;
use App\Http\Requests\Auth\IdpProviderUpdateRequest;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class IdpProviderController extends Controller
{
    public function __construct(private readonly IdpProviderService $service = new IdpProviderService) {}

    public function index(Request $request): JsonResponse
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
        $provider = $this->service->create($payload);

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
        $updated = $this->service->update($model, $payload);

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

        $this->service->delete($model);

        return response()->json([
            'ok' => true,
            'deleted' => $model->key,
        ], 200);
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
            'meta' => $meta === [] ? new \stdClass : $meta,
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
            /** @var array<string,mixed> $value */
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

        return Arr::wrap($value);
    }
}
