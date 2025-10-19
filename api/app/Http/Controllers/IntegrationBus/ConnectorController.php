<?php

declare(strict_types=1);

namespace App\Http\Controllers\IntegrationBus;

use App\Http\Requests\IntegrationBus\ConnectorStoreRequest;
use App\Http\Requests\IntegrationBus\ConnectorUpdateRequest;
use App\Models\IntegrationConnector;
use App\Services\IntegrationBus\ConnectorService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\Ulid;

final class ConnectorController extends Controller
{
    public function __construct(private readonly ConnectorService $service = new ConnectorService) {}

    private function persistenceEnabled(): bool
    {
        return Schema::hasTable('integration_connectors');
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 25,
                    'total' => 0,
                    'total_pages' => 0,
                ],
                'note' => 'stub-only',
            ], 200);
        }

        $pageParam = $request->query('page');
        $page = 1;
        if (is_scalar($pageParam) && is_numeric($pageParam)) {
            $page = (int) $pageParam;
        }
        $page = max(1, $page);

        $perPageParam = $request->query('per_page');
        $perPage = 25;
        if (is_scalar($perPageParam) && is_numeric($perPageParam)) {
            $perPage = (int) $perPageParam;
        }
        $perPage = max(1, min(100, $perPage));

        $paginator = $this->service->paginate($page, $perPage);

        /** @var list<IntegrationConnector> $itemModels */
        $itemModels = $paginator->items();
        $items = array_map(fn (IntegrationConnector $connector): array => $this->formatConnector($connector), $itemModels);

        return response()->json([
            'ok' => true,
            'items' => $items,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ], 200);
    }

    public function store(ConnectorStoreRequest $request): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'connector' => $request->validated(),
            ], 202);
        }

        $connector = $this->service->create($request->validated());

        return response()->json([
            'ok' => true,
            'connector' => $this->formatConnector($connector),
        ], 201);
    }

    public function show(string $connector): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            return response()->json([
                'ok' => false,
                'code' => 'CONNECTOR_PERSISTENCE_DISABLED',
                'note' => 'stub-only',
            ], 409);
        }

        $model = $this->resolveConnector($connector);
        if ($model === null) {
            return $this->notFoundResponse($connector);
        }

        return response()->json([
            'ok' => true,
            'connector' => $this->formatConnector($model),
        ], 200);
    }

    public function update(ConnectorUpdateRequest $request, string $connector): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'connector' => array_merge(['id' => $connector], $request->validated()),
            ], 202);
        }

        $model = $this->resolveConnector($connector);
        if ($model === null) {
            return $this->notFoundResponse($connector);
        }

        $updated = $this->service->update($model, $request->validated());

        return response()->json([
            'ok' => true,
            'connector' => $this->formatConnector($updated),
        ], 200);
    }

    public function destroy(string $connector): JsonResponse
    {
        if (! $this->persistenceEnabled()) {
            return response()->json([
                'ok' => true,
                'note' => 'stub-only',
                'deleted' => $connector,
            ], 202);
        }

        $model = $this->resolveConnector($connector);
        if ($model === null) {
            return $this->notFoundResponse($connector);
        }

        $this->service->delete($model);

        return response()->json([
            'ok' => true,
            'deleted' => $connector,
        ], 200);
    }

    private function resolveConnector(string $connector): ?IntegrationConnector
    {
        $query = IntegrationConnector::query();

        if (Ulid::isValid($connector)) {
            return $query->find($connector);
        }

        return $query->where('integration_connectors.key', '=', $connector)->first();
    }

    private function notFoundResponse(string $connector): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'CONNECTOR_NOT_FOUND',
            'connector' => $connector,
        ], 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatConnector(IntegrationConnector $connector): array
    {
        $config = $this->toArray($connector->getAttribute('config'));
        $meta = $this->toArray($connector->getAttribute('meta'));
        $lastHealth = $connector->last_health_at;
        $createdAt = $connector->created_at;
        $updatedAt = $connector->updated_at;

        /** @var mixed $enabledRaw */
        $enabledRaw = $connector->getAttribute('enabled');

        return [
            'id' => $connector->id,
            'key' => $connector->key,
            'name' => $connector->name,
            'kind' => $connector->kind,
            'enabled' => is_bool($enabledRaw) ? $enabledRaw : (bool) $enabledRaw,
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
