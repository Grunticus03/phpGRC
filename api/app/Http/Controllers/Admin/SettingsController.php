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
        /** @var array{core: array<string, mixed>} $effective */
        $effective = $this->settings->effectiveConfig();

        return new JsonResponse([
            'ok'     => true,
            'config' => $effective, // includes core.metrics
        ], 200);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $raw */
        $raw       = $request->all();
        /** @var array<string,mixed> $validated */
        $validated = $request->validated();

        // Accept both spec shape (top-level) and legacy shape (under core.*)
        /** @var array<string,mixed> $legacy */
        $legacy = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];

        // Build accepted strictly from validated sections to avoid silent drops
        $sections = ['rbac', 'audit', 'evidence', 'avatars', 'metrics', 'ui'];
        /** @var array<string,mixed> $accepted */
        $accepted = [];
        foreach ($sections as $sec) {
            /** @var mixed $val */
            $val = $validated[$sec] ?? ($legacy[$sec] ?? null);
            if (is_array($val)) {
                $accepted[$sec] = $val;
            }
        }

        // Determine apply flag from top-level or legacy core.apply
        $apply = false;
        if ($request->has('apply')) {
            $apply = $request->boolean('apply');
        } elseif (Arr::has($raw, 'core.apply')) {
            /** @var mixed $v */
            $v = Arr::get($raw, 'core.apply');
            $apply = is_bool($v) ? $v
                : (is_int($v) ? $v === 1
                : (is_string($v) && in_array(strtolower($v), ['1','true','on','yes'], true)));
        }

        if (!$apply || !$this->settings->persistenceAvailable()) {
            return new JsonResponse([
                'ok'       => true,
                'applied'  => false,
                'note'     => 'stub-only',
                'accepted' => $accepted,
            ], 200);
        }

        $uid = auth()->id();
        $actorId = is_int($uid) ? $uid : (is_string($uid) && ctype_digit($uid) ? (int) $uid : null);

        $result = $this->settings->apply(
            accepted: $accepted,
            actorId: $actorId,
            context: ['origin' => 'admin.settings']
        );

        return new JsonResponse([
            'ok'       => true,
            'applied'  => true,
            'accepted' => $accepted,
            'changes'  => $result['changes'],
        ], 200);
    }
}
