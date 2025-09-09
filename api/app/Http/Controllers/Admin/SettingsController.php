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

        $stubGate   = (bool) config('core.settings.stub_only', true);
        $canPersist = $this->settings->persistenceAvailable();

        // Respect stub gate always, or lack of storage.
        if ($stubGate || !$canPersist) {
            return response()->json([
                'ok'       => true,
                'applied'  => false,
                'note'     => 'stub-only',
                'accepted' => $accepted,
            ], 200);
        }

        // Determine if an explicit apply flag was provided.
        $hasApplyRoot   = $request->has('apply');
        $hasApplyLegacy = Arr::has($raw, 'core.apply');
        $applyProvided  = $hasApplyRoot || $hasApplyLegacy;

        // Parse apply value if provided; otherwise default to true in persistence mode.
        $apply = true;
        if ($applyProvided) {
            $applyInput = $request->input('apply', Arr::get($raw, 'core.apply'));
            $applyBool  = filter_var($applyInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($applyBool === null) {
                $s = is_string($applyInput) ? strtolower($applyInput) : '';
                $apply = in_array($s, ['1', 'true', 'on', 'yes'], true) || $applyInput === 1 || $applyInput === true;
            } else {
                $apply = $applyBool;
            }
        }

        if (!$apply) {
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

