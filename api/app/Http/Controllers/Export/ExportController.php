<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Models\Export;
use App\Services\Export\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
     * If persistence is active and export is completed, stream the artifact.
     * Otherwise, 404 EXPORT_NOT_READY (Phase 4 behavior).
     */
    public function download(string $jobId): HttpResponse
    {
        if (!$this->persistenceOn()) {
            return response()->json([
                'ok'    => false,
                'code'  => 'EXPORT_NOT_READY',
                'note'  => 'stub-only',
                'jobId' => $jobId,
            ], 404);
        }

        /** @var Export|null $export */
        $export = Export::query()->find($jobId);
        if ($export === null) {
            return response()->json([
                'ok'   => false,
                'code' => 'EXPORT_NOT_FOUND',
                'id'   => $jobId,
            ], 404);
        }

        if ($export->status === 'failed') {
            return response()->json([
                'ok'        => false,
                'code'      => 'EXPORT_FAILED',
                'jobId'     => $export->id,
                'errorCode' => (string) ($export->error_code ?? ''),
                'errorNote' => (string) ($export->error_note ?? ''),
            ], 409);
        }

        if ($export->status !== 'completed' || empty($export->artifact_path)) {
            return response()->json([
                'ok'    => false,
                'code'  => 'EXPORT_NOT_READY',
                'jobId' => $export->id,
            ], 404);
        }

        $disk = $export->artifact_disk ?: config('filesystems.default', 'local');
        if (!Storage::disk($disk)->exists($export->artifact_path)) {
            return response()->json([
                'ok'    => false,
                'code'  => 'EXPORT_ARTIFACT_MISSING',
                'jobId' => $export->id,
            ], 410);
        }

        $filename = "export-{$export->id}." . $export->type;

        // Canonicalize MIME to avoid framework-default surprises.
        $mime = match ($export->type) {
            'csv'  => 'text/csv; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'pdf'  => 'application/pdf',
            default => (is_string($export->artifact_mime) && str_contains($export->artifact_mime, '/'))
                ? $export->artifact_mime
                : 'application/octet-stream',
        };

        $headers = [
            'Content-Type'              => $mime,
            'X-Content-Type-Options'    => 'nosniff',
        ];

        return Response::download(
            Storage::disk($disk)->path($export->artifact_path),
            $filename,
            $headers
        );
    }
}

