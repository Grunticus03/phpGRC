<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\SettingsUpdated;
use App\Exceptions\DesignerThemeException;
use App\Http\Requests\Settings\DesignerThemeImportRequest;
use App\Http\Requests\Settings\DesignerThemeStoreRequest;
use App\Services\Settings\DesignerThemeStorageService;
use App\Services\Settings\ThemePackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;

final class DesignerThemesController extends Controller
{
    public function __construct(
        private readonly DesignerThemeStorageService $storage,
        private readonly ThemePackService $themePacks
    ) {}

    public function index(): JsonResponse
    {
        $config = $this->storage->storageConfig();
        $packs = $this->storage->manifestEntries();

        return response()->json([
            'ok' => true,
            'storage' => $config,
            'themes' => $packs,
        ]);
    }

    public function store(DesignerThemeStoreRequest $request): JsonResponse
    {
        /** @var array{name:string, slug?:string|null, variables:array<string,string>} $payload */
        $payload = $request->validated();

        $name = $payload['name'];
        $slugInput = $payload['slug'] ?? null;
        $variables = $payload['variables'];

        $slug = is_string($slugInput) && trim($slugInput) !== ''
            ? $slugInput
            : $this->slugFromName($name);

        if ($slug === '') {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_INVALID',
                'message' => 'Unable to determine a valid slug for the theme.',
            ], 422);
        }

        $existing = $this->findManifestEntry($slug);
        if ($existing !== null && ($existing['source'] ?? '') !== 'custom') {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_CONFLICT',
                'message' => 'Slug conflicts with a built-in or imported theme.',
            ], 409);
        }

        [$actorId] = $this->resolveActor($request);

        try {
            $pack = $this->storage->save($name, $slug, $variables, $actorId);
        } catch (DesignerThemeException $e) {
            return $this->errorResponse($e);
        }

        $this->emitSettingsEvent($actorId, [
            [
                'key' => 'ui.theme.designer.pack',
                'old' => $existing,
                'new' => $pack,
                'action' => $existing === null ? 'set' : 'update',
            ],
        ], $request);

        return response()->json([
            'ok' => true,
            'pack' => $pack,
        ], $existing === null ? 201 : 200);
    }

    public function import(DesignerThemeImportRequest $request): JsonResponse
    {
        /** @var UploadedFile|null $uploaded */
        $uploaded = $request->file('file');
        if (! $uploaded instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_UPLOAD_FAILED',
                'message' => 'No theme file provided.',
            ], 400);
        }

        $bytes = $uploaded->get();
        if (! is_string($bytes) || $bytes === '') {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_UPLOAD_FAILED',
                'message' => 'Unable to read uploaded theme file.',
            ], 400);
        }

        /** @var array<string,mixed> $validated */
        $validated = $request->validated();
        $slugOverride = null;
        if (isset($validated['slug']) && is_string($validated['slug']) && trim($validated['slug']) !== '') {
            $slugOverride = $validated['slug'];
        }

        try {
            $parsed = $this->storage->parseImportPayload($bytes, $slugOverride);
        } catch (DesignerThemeException $e) {
            return $this->errorResponse($e);
        }

        $existing = $this->findManifestEntry($parsed['slug']);
        if ($existing !== null && ($existing['source'] ?? '') !== 'custom') {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_CONFLICT',
                'message' => 'Slug conflicts with a built-in or imported theme.',
            ], 409);
        }

        [$actorId] = $this->resolveActor($request);

        try {
            $pack = $this->storage->save(
                $parsed['name'],
                $parsed['slug'],
                $parsed['variables'],
                $actorId,
                $parsed['supports']
            );
        } catch (DesignerThemeException $e) {
            return $this->errorResponse($e);
        }

        $this->emitSettingsEvent($actorId, [
            [
                'key' => 'ui.theme.designer.pack',
                'old' => $existing,
                'new' => $pack,
                'action' => $existing === null ? 'set' : 'update',
            ],
        ], $request);

        return response()->json([
            'ok' => true,
            'pack' => $pack,
        ], $existing === null ? 201 : 200);
    }

    public function export(Request $request, string $slug): Response|JsonResponse
    {
        try {
            $result = $this->storage->export($slug);
        } catch (DesignerThemeException $e) {
            return $this->errorResponse($e);
        }

        $contents = $result['contents'];
        $filename = $result['filename'];
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => sprintf('W/"designer-theme:%s"', hash('sha256', $contents)),
        ];

        return response($contents, 200, $headers);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $existing = $this->findManifestEntry($slug);
        if ($existing === null || ($existing['source'] ?? '') !== 'custom') {
            return response()->json([
                'ok' => false,
                'code' => 'DESIGNER_THEME_NOT_FOUND',
                'message' => 'Theme not found.',
            ], 404);
        }

        [$actorId] = $this->resolveActor($request);

        try {
            $this->storage->delete($slug);
        } catch (DesignerThemeException $e) {
            return $this->errorResponse($e);
        }

        $this->emitSettingsEvent($actorId, [
            [
                'key' => 'ui.theme.designer.pack',
                'old' => $existing,
                'new' => null,
                'action' => 'unset',
            ],
        ], $request);

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  list<array{key:string,old:mixed,new:mixed,action:string}>  $changes
     */
    private function emitSettingsEvent(?int $actorId, array $changes, Request $request): void
    {
        if ($changes === []) {
            return;
        }

        event(new SettingsUpdated(
            actorId: $actorId,
            changes: $changes,
            context: [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'route' => $request->path(),
                'source' => 'ui.theme.designer',
            ],
            occurredAt: now('UTC')
        ));
    }

    /**
     * @return array{0:int|null,1:string|null}
     */
    private function resolveActor(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [null, null];
        }

        $actorId = null;
        /** @var mixed $id */
        $id = $user->getAuthIdentifier();
        if (is_int($id)) {
            $actorId = $id;
        } elseif (is_string($id) && ctype_digit($id)) {
            $actorId = (int) $id;
        }

        /** @var mixed $nameAttr */
        $nameAttr = $user->getAttribute('name');
        $actorName = is_string($nameAttr) && trim($nameAttr) !== ''
            ? mb_substr(trim($nameAttr), 0, 120)
            : null;

        return [$actorId, $actorName];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findManifestEntry(string $slug): ?array
    {
        $needle = trim($slug);
        if ($needle === '') {
            return null;
        }

        $manifest = $this->themePacks->manifest();

        foreach ($manifest['themes'] as $theme) {
            /** @var array<string,mixed> $theme */
            if (($theme['slug'] ?? null) === $needle) {
                return $theme;
            }
        }

        foreach ($manifest['packs'] as $pack) {
            /** @var array<string,mixed> $pack */
            if (($pack['slug'] ?? null) === $needle) {
                return $pack;
            }
        }

        return null;
    }

    private function slugFromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');

        if ($slug !== '' && mb_strlen($slug) > 64) {
            $slug = mb_substr($slug, 0, 64);
            $slug = trim(preg_replace('/-+$/', '', $slug) ?? '', '-');
        }

        return $slug;
    }

    private function errorResponse(DesignerThemeException $e): JsonResponse
    {
        $payload = [
            'ok' => false,
            'code' => $e->apiCode,
            'message' => $e->getMessage(),
        ];

        if ($e->context !== []) {
            $payload['context'] = $e->context;
        }

        return response()->json($payload, $e->status);
    }
}
