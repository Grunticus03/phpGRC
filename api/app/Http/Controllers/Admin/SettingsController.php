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
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        $effective = $this->settings->effectiveConfig();

        return response()->json([
            'ok'     => true,
            'config' => ['core' => $effective['core']],
        ], 200);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        // Accept both shapes; keep only contract keys.
        $raw       = (array) $request->all();
        $validated = (array) $request->validated();
        $legacy    = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];
        $accepted  = Arr::only($legacy + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Apply ONLY when an explicit flag is present and truthy (root or legacy core.apply).
        $apply = false;
        if ($request->has('apply')) {
            $apply = $request->boolean('apply');
        } elseif (Arr::has($raw, 'core.apply')) {
            $v = Arr::get($raw, 'core.apply');
            $apply = is_bool($v) ? $v
                : (is_int($v) ? $v === 1
                : (is_string($v) && in_array(strtolower($v), ['1','true','on','yes'], true)));
        }

        if (!$apply || !$this->settings->persistenceAvailable()) {
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
