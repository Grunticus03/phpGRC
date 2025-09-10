<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Services\Export\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class ExportController extends Controller
{
    public function __construct(private readonly ExportService $service = new ExportService()) {}

    private function persistenceOn(): bool
    {
        return (bool) config('core.exports.enabled', false) && Schema::hasTable('exports');
    }

    /**
     * POST /api/exports (legacy)
     * Body: { "type": "csv"|"json"|"pdf", "params": {...} }
     */
    public function create(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        if (!in_array($type, ['csv', 'json', 'pdf'], true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'EXPORT_TYPE_UNSUPPORTED',
                'note' => 'stub-only',
            ], 422);
        }

        $validated = $request->validate([
            'params' => ['sometimes', 'array'],
        ]);
        $params = (array) ($validated['params'] ?? []);

        if ($this->persistenceOn()) {
            $export = $this->service->enqueue($type, $params);

            return response()->json([
                'ok'     => true,
                'jobId'  => $export->id,
                'type'   => $type,
                'params' => $params,
            ], 202);
        }

        return response()->json([
            'ok'     => true,
            'jobId'  => 'exp_stub_0001',
            'type'   => $type,
            'params' => $params ?: new \stdClass(),
            'note'   => 'stub-only',
        ], 202);
    }

    /**
     * POST /api/exports/{type}
     * Body: { "params": {...} }
     */
    public function createType(Request $request, string $type): JsonResponse
    {
        if (!in_array($type, ['csv', 'json', 'pdf'], true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'EXPORT_TYPE_UNSUPPORTED',
                'note' => 'stub-only',
            ], 422);
        }

        $validated = $request->validate([
            'params' => ['sometimes', 'array'],
        ]);
        $params = (array) ($validated['params'] ?? []);

        if ($this->persistenceOn()) {
            $export = $this->service->enqueue($type, $params);

            return response()->json([
                'ok'     => true,
                'jobId'  => $export->id,
                'type'   => $type,
                'params' => $params,
            ], 202);
        }

        return response()->json([
            'ok'     => true,
            'jobId'  => 'exp_stub_0001',
            'type'   => $type,
            'params' => $params ?: new \stdClass(),
            'note'   => 'stub-only',
        ], 202);
    }

    /**
     * GET /api/exports/{jobId}/download
     * Phase 4: always not ready.
     */
    public function download(string $jobId): JsonResponse
    {
        return response()->json([
            'ok'    => false,
            'code'  => 'EXPORT_NOT_READY',
            'note'  => 'stub-only',
            'jobId' => $jobId,
        ], 404);
    }
}

