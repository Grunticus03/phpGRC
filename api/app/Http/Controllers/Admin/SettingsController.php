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
        $raw       = (array) $request->all(); // may include legacy { core: {...} }
        $validated = (array) $request->validated();
        $legacy    = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];

        $accepted  = Arr::only($legacy + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Explicit apply flag only. If absent/false => stub-only.
        $applyRequested = false;
        if ($request->has('apply')) {
            $applyRequested = $request->boolean('apply');
        }
        if (Arr::has($raw, 'core.apply')) {
            $v = Arr::get($raw, 'core.apply');
            $applyRequested = $applyRequested || (is_bool($v) ? $v
                : (is_int($v) ? $v === 1
                : (is_string($v) ? in_array(strtolower($v), ['1','true','on','yes'], true) : false)));
        }

        // Ignore stub gate for explicit apply. Apply if table exists; else stub-only.
        if ($applyRequested && $this->settings->persistenceAvailable()) {
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

        // Default: stub-only echo
        return response()->json([
            'ok'       => true,
            'applied'  => false,
            'note'     => 'stub-only',
            'accepted' => $accepted,
        ], 200);
    }
}

