<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\SettingsUpdated;
use App\Exceptions\ThemePackException;
use App\Http\Requests\Settings\ThemePackImportRequest;
use App\Http\Requests\Settings\ThemePackUpdateRequest;
use App\Models\UiThemePack;
use App\Services\Settings\ThemePackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;

final class ThemePacksController extends Controller
{
    public function __construct(private readonly ThemePackService $themePacks) {}

    public function import(ThemePackImportRequest $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'code' => 'UPLOAD_FAILED',
                'message' => 'No file provided.',
            ], 400);
        }

        [$actorId, $actorName] = $this->resolveActor($request);

        try {
            $result = $this->themePacks->import($file, $actorId, $actorName);
        } catch (ThemePackException $e) {
            return $this->themeError($e);
        }

        $this->emitSettingsEvent($actorId, $result['changes'], $request);

        return response()->json([
            'ok' => true,
            'pack' => $this->transformPack($result['pack']),
        ], 201);
    }

    public function update(ThemePackUpdateRequest $request, string $slug): JsonResponse
    {
        /** @var array<string,mixed> $payload */
        $payload = $request->validated();
        [$actorId, $actorName] = $this->resolveActor($request);

        try {
            $result = $this->themePacks->update($slug, $payload, $actorId);
        } catch (ThemePackException $e) {
            return $this->themeError($e);
        }

        $this->emitSettingsEvent($actorId, $result['changes'], $request);

        return response()->json([
            'ok' => true,
            'pack' => $this->transformPack($result['pack']),
            'affected_users' => $result['affected_users'],
            'default_reset' => $result['default_reset'],
        ], 200);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        [$actorId, $actorName] = $this->resolveActor($request);

        try {
            $result = $this->themePacks->delete($slug, $actorId);
        } catch (ThemePackException $e) {
            return $this->themeError($e);
        }

        $this->emitSettingsEvent($actorId, $result['changes'], $request);

        return response()->json([
            'ok' => true,
            'files_removed' => $result['files_removed'],
            'affected_users' => $result['affected_users'],
            'default_reset' => $result['default_reset'],
        ], 200);
    }

    /**
     * @param  list<array{key:string, old:mixed, new:mixed, action:string}>  $changes
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
                'source' => 'ui.theme.packs',
            ],
            occurredAt: now('UTC')
        ));
    }

    private function themeError(ThemePackException $e): JsonResponse
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
     * @return array<string,mixed>
     */
    private function transformPack(UiThemePack $pack): array
    {
        /** @var string $slug */
        $slug = $pack->getAttribute('slug');
        /** @var string $name */
        $name = $pack->getAttribute('name');
        /** @var bool $enabled */
        $enabled = (bool) $pack->getAttribute('enabled');
        /** @var mixed $version */
        $version = $pack->getAttribute('version');
        /** @var mixed $author */
        $author = $pack->getAttribute('author');
        /** @var mixed $licenseName */
        $licenseName = $pack->getAttribute('license_name');
        /** @var mixed $licenseFile */
        $licenseFile = $pack->getAttribute('license_file');
        /** @var array<string,string>|array<int,string>|null $assets */
        $assets = $pack->getAttribute('assets');
        /** @var mixed $inactive */
        $inactive = $pack->getAttribute('inactive');
        /** @var mixed $files */
        $files = $pack->getAttribute('files');

        /** @var \Carbon\CarbonInterface|\DateTimeInterface|string|null $createdAt */
        $createdAt = $pack->getAttribute('created_at');

        $assetsMap = [];
        if (is_array($assets)) {
            foreach ($assets as $mode => $path) {
                if (is_string($mode) && $path !== '') {
                    $assetsMap[$mode] = $path;
                }
            }
        }

        $inactiveSets = is_array($inactive) ? $inactive : [];

        $payload = [
            'slug' => $slug,
            'name' => $name,
            'enabled' => $enabled,
            'version' => is_string($version) && $version !== '' ? $version : null,
            'author' => is_string($author) && $author !== '' ? $author : null,
            'assets' => $assetsMap,
            'inactive' => [
                'html' => $this->normalizeStringList($inactiveSets['html'] ?? []),
                'js' => $this->normalizeStringList($inactiveSets['js'] ?? []),
            ],
            'files' => is_array($files) ? $files : [],
        ];

        if (is_string($licenseName) && $licenseName !== '' && is_string($licenseFile) && $licenseFile !== '') {
            $payload['license'] = [
                'name' => $licenseName,
                'file' => $licenseFile,
            ];
        } else {
            $payload['license'] = null;
        }

        if ($createdAt instanceof \Carbon\CarbonInterface) {
            $payload['created_at'] = $createdAt->toJSON();
        }

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $seen = [];
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trim = trim($item);
            if ($trim === '') {
                continue;
            }
            if (isset($seen[$trim])) {
                continue;
            }
            $seen[$trim] = true;
            $result[] = $trim;
        }

        return $result;
    }
}
