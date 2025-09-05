<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ExportController extends Controller
{
    /**
     * POST /api/exports
     * Body: { "type": "csv"|"json"|"pdf" , "params": {...} }
     * Stub: returns a fixed jobId and echoes type/params.
     */
    public function create(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        $allowed = ['csv', 'json', 'pdf'];
        if (! in_array($type, $allowed, true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'EXPORT_TYPE_INVALID',
                'note' => 'stub-only',
            ], 400);
        }

        // Stub-only: deterministic job id
        $jobId = 'exp_stub_0001';

        return response()->json([
            'ok'    => true,
            'jobId' => $jobId,
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
