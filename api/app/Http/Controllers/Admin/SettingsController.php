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
        // Accept both shapes; only keep contract keys.
        $raw       = (array) $request->all();
        $validated = (array) $request->validated();
        $legacy    = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];
        $accepted  = Arr::only($legacy + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

        // Default behavior comes from stub gate: true => stub-only, false => apply.
        $stubOnly = (bool) config('core.settings.stub_only', true);
        $apply    = !$stubOnly;

        // If request provides apply (root or core.apply), honor it.
        if ($request->has('apply') || Arr::has($raw, 'core.apply')) {
            $v = $request->input('apply', Arr::get($raw, 'core.apply'));
            if (is_bool($v)) {
                $apply = $v;
            } elseif (is_int($v)) {
                $apply = ($v === 1);
            } elseif (is_string($v)) {
                $apply = in_array(strtolower($v), ['1','true','on','yes'], true);
            }
        }

        // If not applying or no persistence, echo stub-only.
        if (!$apply || !$this->settings->persistenceAvailable()) {
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

