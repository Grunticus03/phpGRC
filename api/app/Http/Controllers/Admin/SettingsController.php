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

        // Determine apply:
        // - If an explicit flag is present (root or core.apply), honor it.
        // - Otherwise: POST => false, PUT/PATCH => true.
        $hasApply = $request->has('apply') || Arr::has($raw, 'core.apply');
        if ($hasApply) {
            $v = $request->input('apply', Arr::get($raw, 'core.apply'));
            $apply = match (true) {
                is_bool($v)   => $v,
                is_int($v)    => $v === 1,
                is_string($v) => in_array(strtolower($v), ['1','true','on','yes'], true),
                default       => false,
            };
        } else {
            $method = strtoupper($request->getMethod());
            $apply = in_array($method, ['PUT','PATCH'], true);
        }

        // Apply only when storage exists; else stub-only echo.
        if ($apply && $this->settings->persistenceAvailable()) {
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

        return response()->json([
            'ok'       => true,
            'applied'  => false,
            'note'     => 'stub-only',
            'accepted' => $accepted,
        ], 200);
    }
}

