<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

/**
 * @psalm-type CoreFlags=array{
 *   rbac: array{enabled: bool},
 *   audit: array{enabled: bool},
 *   evidence: array{enabled: bool},
 *   avatars: array{enabled: bool},
 * }
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        /** @var array<string,mixed> $effective */
        $effective = $this->settings->effectiveConfig();

        /** @var CoreFlags $core */
        $core = [
            'rbac' => [
                'enabled' => (bool) data_get($effective, 'core.rbac.enabled', true),
            ],
            'audit' => [
                'enabled' => (bool) data_get($effective, 'core.audit.enabled', false),
            ],
            'evidence' => [
                'enabled' => (bool) data_get($effective, 'core.evidence.enabled', true),
            ],
            'avatars' => [
                'enabled' => (bool) data_get($effective, 'core.avatars.enabled', false),
            ],
        ];

        return new JsonResponse([
            'ok'     => true,
            'config' => ['core' => $core],
        ], 200);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $raw */
        $raw       = $request->all();
        /** @var array<string,mixed> $validated */
        $validated = $request->validated();
        /** @var array<string,mixed> $legacy */
        $legacy    = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];
        /** @var array<string,mixed> $accepted */
        $accepted  = Arr::only($legacy + $validated, ['rbac', 'audit', 'evidence', 'avatars']);

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
