<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Requests\User\UserUiPreferencesUpdateRequest;
use App\Services\Settings\UserUiPreferencesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class UiPreferencesController extends Controller
{
    public function __construct(private readonly UserUiPreferencesService $prefs) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        /** @var mixed $id */
        $id = $user->getAuthIdentifier();
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return response()->json([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $userId = is_int($id) ? $id : (int) $id;

        $prefs = $this->prefs->get($userId);
        $etag = $this->prefs->etagFor($prefs);

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
            'prefs' => $prefs,
            'etag' => $etag,
        ], 200)->withHeaders($headers);
    }

    public function update(UserUiPreferencesUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        /** @var mixed $id */
        $id = $user->getAuthIdentifier();
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return response()->json([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $userId = is_int($id) ? $id : (int) $id;

        $current = $this->prefs->get($userId);
        $currentEtag = $this->prefs->etagFor($current);
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
        $prefs = $this->prefs->apply($userId, $validated);
        $newEtag = $this->prefs->etagFor($prefs);

        return response()->json([
            'ok' => true,
            'prefs' => $prefs,
            'etag' => $newEtag,
        ], 200)->withHeaders([
            'ETag' => $newEtag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
