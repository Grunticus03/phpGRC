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
        $validated   = (array) $request->validated();
        $legacyCore  = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];
        $accepted    = Arr::only($legacyCore + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Discover the "apply" intent (default false). Accepts root or legacy core.apply.
        $applyInput = $request->input('apply', Arr::get($raw, 'core.apply', false));
        $applyBool  = filter_var($applyInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $apply      = $applyBool !== null ? $applyBool : (bool) $applyInput;

        // Global stub gate plus table availability.
        $stubOnly = (bool) config('core.settings.stub_only', true);

        // If not applying, or stub-only is on, or persistence unavailable => stub-only response.
        if (!$apply || $stubOnly || !$this->settings->persistenceAvailable()) {
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

