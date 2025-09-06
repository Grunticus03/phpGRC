<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ExportController extends Controller
{
    /**
     * POST /api/exports (legacy)
     * Body: { "type": "csv"|"json"|"pdf", "params": {...} }
     * Stub: returns a fixed jobId and echoes type/params.
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

        $request->validate([
            'params' => ['sometimes', 'array'],
        ]);

        return response()->json([
            'ok'    => true,
            'jobId' => 'exp_stub_0001',
            'type'  => $type,
            'params'=> $request->input('params', new \stdClass()),
            'note'  => 'stub-only',
        ], 202);
    }

    /**
     * POST /api/exports/{type} (spec-preferred)
     * Body: { "params": {...} }
     * Stub: returns a fixed jobId and echoes type/params.
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

        $request->validate([
            'params' => ['sometimes', 'array'],
        ]);

        return response()->json([
            'ok'    => true,
            'jobId' => 'exp_stub_0001',
            'type'  => $type,
            'params'=> $request->input('params', new \stdClass()),
            'note'  => 'stub-only',
        ], 202);
    }

    /**
     * GET /api/exports/{jobId}/download
     * Stub: always not ready.
     */
    public function download(string $jobId): JsonResponse
    {
        return response()->json([
            'ok'   => false,
            'code' => 'EXPORT_NOT_READY',
            'note' => 'stub-only',
            'jobId'=> $jobId,
        ], 404);
    }
}
