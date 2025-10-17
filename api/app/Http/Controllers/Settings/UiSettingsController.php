<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\SettingsUpdated;
use App\Http\Requests\Settings\UiSettingsUpdateRequest;
use App\Services\Settings\Exceptions\BrandProfileLockedException;
use App\Services\Settings\Exceptions\BrandProfileNotFoundException;
use App\Services\Settings\UiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class UiSettingsController extends Controller
{
    public function __construct(private readonly UiSettingsService $settings) {}

    public function show(Request $request): Response
    {
        /** @var array{
         *     theme: array{
         *         default: string,
         *         allow_user_override: bool,
         *         force_global: bool,
         *         overrides: array<string,string|null>,
         *         designer: array{storage:string, filesystem_path:string},
         *         login: array{layout:string}
         *     },
         *     nav: array{sidebar: array{default_order: array<int,string>}},
         *     brand: array{
         *         title_text: string,
         *         favicon_asset_id: string|null,
         *         primary_logo_asset_id: string|null,
         *         secondary_logo_asset_id: string|null,
         *         header_logo_asset_id: string|null,
         *         footer_logo_asset_id: string|null,
         *         background_login_asset_id: string|null,
         *         background_main_asset_id: string|null,
         *         footer_logo_disabled: bool,
         *         assets: array{filesystem_path: string}
         *     }
         * } $config
         */
        $config = $this->settings->currentConfig();
        $etag = $this->settings->etagFor($config);

        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];

        if ($this->etagMatches($request->headers->get('If-None-Match'), $etag)) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return response()->json([
            'ok' => true,
            'config' => ['ui' => $config],
            'etag' => $etag,
        ], 200)->withHeaders($headers);
    }

    public function update(UiSettingsUpdateRequest $request): JsonResponse
    {
        /** @var array{
         *     theme: array{
         *         default: string,
         *         allow_user_override: bool,
         *         force_global: bool,
         *         overrides: array<string,string|null>,
         *         designer: array{storage:string, filesystem_path:string},
         *         login: array{layout:string}
         *     },
         *     nav: array{sidebar: array{default_order: array<int,string>}},
         *     brand: array{
         *         title_text: string,
         *         favicon_asset_id: string|null,
         *         primary_logo_asset_id: string|null,
         *         secondary_logo_asset_id: string|null,
         *         header_logo_asset_id: string|null,
         *         footer_logo_asset_id: string|null,
         *         background_login_asset_id: string|null,
         *         background_main_asset_id: string|null,
         *         footer_logo_disabled: bool,
         *         assets: array{filesystem_path: string}
         *     }
         * } $configBefore
         */
        $configBefore = $this->settings->currentConfig();
        $currentEtag = $this->settings->etagFor($configBefore);
        $baseHeaders = [
            'ETag' => $currentEtag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];

        if (! $this->etagMatches($request->headers->get('If-Match'), $currentEtag)) {
            return response()->json([
                'ok' => false,
                'code' => 'PRECONDITION_FAILED',
                'message' => 'If-Match header required or did not match current version.',
                'current_etag' => $currentEtag,
            ], 409)->withHeaders($baseHeaders);
        }

        /** @var array<string,mixed> $validated */
        $validated = $request->validated();
        /** @var array<string,mixed> $payload */
        $payload = isset($validated['ui']) && is_array($validated['ui']) ? $validated['ui'] : [];

        $user = $request->user();
        $actorId = null;
        if ($user !== null) {
            /** @var mixed $id */
            $id = $user->getAuthIdentifier();
            if (is_int($id)) {
                $actorId = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $actorId = (int) $id;
            }
        }

        try {
            $result = $this->settings->apply($payload, $actorId);
        } catch (BrandProfileNotFoundException $exception) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => $exception->getMessage(),
            ], 404)->withHeaders($baseHeaders);
        } catch (BrandProfileLockedException $exception) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_LOCKED',
                'message' => $exception->getMessage(),
            ], 409)->withHeaders($baseHeaders);
        }

        $this->emitSettingsEvent($actorId, $result['changes'], $request);
        /** @var array{
         *     theme: array{
         *         default: string,
         *         allow_user_override: bool,
         *         force_global: bool,
         *         overrides: array<string,string|null>,
         *         designer: array{storage:string, filesystem_path:string},
         *         login: array{layout:string}
         *     },
         *     nav: array{sidebar: array{default_order: array<int,string>}},
         *     brand: array{
         *         title_text: string,
         *         favicon_asset_id: string|null,
         *         primary_logo_asset_id: string|null,
         *         secondary_logo_asset_id: string|null,
         *         header_logo_asset_id: string|null,
         *         footer_logo_asset_id: string|null,
         *         background_login_asset_id: string|null,
         *         background_main_asset_id: string|null,
         *         footer_logo_disabled: bool,
         *         assets: array{filesystem_path: string}
         *     }
         * } $config
         */
        $config = $result['config'];
        $newEtag = $this->settings->etagFor($config);

        return response()->json([
            'ok' => true,
            'config' => ['ui' => $config],
            'etag' => $newEtag,
            'changes' => $result['changes'],
        ], 200)->withHeaders([
            'ETag' => $newEtag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
                'source' => 'ui.settings',
            ],
            occurredAt: now('UTC')
        ));
    }

    private function etagMatches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $candidates = array_filter(array_map('trim', explode(',', $header)));

        foreach ($candidates as $candidate) {
            if ($candidate === '*') {
                return true;
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
