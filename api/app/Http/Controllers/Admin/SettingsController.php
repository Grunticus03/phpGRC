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
        $raw       = (array) $request->all(); // may be legacy { core: {...} }
        $validated = (array) $request->validated();
        $legacy    = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];

        $accepted  = Arr::only($legacy + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Apply only if an explicit flag is present and truthy (root or legacy).
        $applyProvided = $request->has('apply') || Arr::has($raw, 'core.apply');
        $applyRequested = false;
        if ($applyProvided) {
            $v = $request->input('apply', Arr::get($raw, 'core.apply'));
            if (is_bool($v)) {
                $applyRequested = $v;
            } elseif (is_int($v)) {
                $applyRequested = ($v === 1);
            } elseif (is_string($v)) {
                $applyRequested = in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
            }
        }

        if (!$applyProvided || !$applyRequested || !$this->settings->persistenceAvailable()) {
            return response()->json([
                'ok'       => true,
                'applied'  => false,
                'note'     => 'stub-only',
                'accepted' => $accepted,
            ], 200);
        }

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

