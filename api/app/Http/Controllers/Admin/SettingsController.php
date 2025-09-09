<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function index(): JsonResponse
    {
        $effective = $this->settings->effectiveConfig(); // ['core' => [...]]

        return response()->json([
            'ok'     => true,
            'config' => ['core' => $effective['core']],
        ], 200);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        // Accept both flat and legacy { core: {...} } payloads.
        $raw = $request->all();

        $accepted = Arr::has($raw, 'core') && is_array($raw['core'])
            ? Arr::only((array) $raw['core'], ['rbac', 'audit', 'evidence', 'avatars'])
            : Arr::only($request->validated(), ['rbac', 'audit', 'evidence', 'avatars']);

        // If persistence is not available, return stub-only per Phase-1 contract.
        if (!$this->settings->persistenceAvailable()) {
            return response()->json([
                'ok'       => true,
                'applied'  => false,
                'note'     => 'stub-only',
                'accepted' => $accepted,
            ], 200);
        }

        // Persist overrides and report changes.
        $result = $this->settings->apply(
            accepted: $accepted,
            actorId: auth()->id() ?? null,
            context: ['origin' => 'admin.settings']
        );

        return response()->json([
            'ok'       => true,
            'applied'  => true,
            'accepted' => $accepted,
            'changes'  => $result['changes'],
        ], 200);
    }
}

