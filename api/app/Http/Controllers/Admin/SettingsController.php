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
        // Raw payload (may be legacy { core: {...} } or flat)
        $raw = (array) $request->all();

        // Normalize accepted sections from either legacy or flat payloads.
        $validated  = (array) $request->validated();
        $legacyCore = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];
        $accepted   = Arr::only($legacyCore + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Explicit apply flag only. If absent or false => stub-only.
        $applyRequested = false;
        if ($request->has('apply')) {
            $applyRequested = $request->boolean('apply');
        }
        if (Arr::has($raw, 'core.apply')) {
            $v = Arr::get($raw, 'core.apply');
            if (is_bool($v)) {
                $applyRequested = $applyRequested || $v;
            } elseif (is_int($v)) {
                $applyRequested = $applyRequested || ($v === 1);
            } elseif (is_string($v)) {
                $applyRequested = $applyRequested || in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
            }
        }

        // Stub-only unless explicit apply AND persistence enabled AND stub gate off.
        $stubGate = (bool) config('core.settings.stub_only', true);
        $canPersist = $this->settings->persistenceAvailable();

        if (!$applyRequested || $stubGate || !$canPersist) {
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

