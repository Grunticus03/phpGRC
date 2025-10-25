<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): Response
    {
        /** @var array{core: array<string, mixed>} $effective */
        $effective = $this->settings->effectiveConfig();
        $etag = $this->settings->etagFor($effective);

        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];

        if ($this->etagMatches($request->headers->get('If-None-Match'), $etag)) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return response()->json([
            'ok' => true,
            'config' => $effective, // includes core.metrics
        ], 200)->withHeaders($headers);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $raw */
        $raw = $request->all();
        /** @var array<string,mixed> $validated */
        $validated = $request->validated();

        /** @var array{core: array<string, mixed>} $effectiveBefore */
        $effectiveBefore = $this->settings->effectiveConfig();
        $currentEtag = $this->settings->etagFor($effectiveBefore);
        $baseHeaders = [
            'ETag' => $currentEtag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];

        // Accept both top-level sections and core.*-scoped payloads
        /** @var array<string,mixed> $coreScoped */
        $coreScoped = is_array(Arr::get($raw, 'core')) ? (array) $raw['core'] : [];

        // Build accepted strictly from validated sections to avoid silent drops
        $sections = ['rbac', 'audit', 'evidence', 'avatars', 'metrics', 'ui', 'saml'];
        /** @var array<string,mixed> $accepted */
        $accepted = [];
        foreach ($sections as $sec) {
            /** @var mixed $val */
            $val = $validated[$sec] ?? ($coreScoped[$sec] ?? null);
            if (is_array($val)) {
                $accepted[$sec] = $val;
            }
        }

        // Determine apply flag from top-level or core.apply input
        $apply = false;
        if ($request->has('apply')) {
            $apply = $request->boolean('apply');
        } elseif (Arr::has($raw, 'core.apply')) {
            /** @var mixed $v */
            $v = Arr::get($raw, 'core.apply');
            $apply = is_bool($v) ? $v
                : (is_int($v) ? $v === 1
                : (is_string($v) && in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true)));
        }

        if (! $apply || ! $this->settings->persistenceAvailable()) {
            return response()->json([
                'ok' => true,
                'applied' => false,
                'note' => 'stub-only',
                'accepted' => $accepted,
                'config' => $effectiveBefore,
                'etag' => $currentEtag,
            ], 200)->withHeaders($baseHeaders);
        }

        $uid = auth()->id();
        $actorId = is_int($uid) ? $uid : (is_string($uid) && ctype_digit($uid) ? (int) $uid : null);

        $ipRaw = $request->ip();
        $uaRaw = $request->userAgent();
        $context = [
            'origin' => 'admin.settings',
            'ip' => is_string($ipRaw) && $ipRaw !== '' ? $ipRaw : null,
            'ua' => is_string($uaRaw) && $uaRaw !== '' ? $uaRaw : null,
        ];

        $actor = $request->user();
        if ($actor instanceof User) {
            /** @var mixed $nameAttr */
            $nameAttr = $actor->getAttribute('name');
            if (is_string($nameAttr)) {
                $name = trim($nameAttr);
                if ($name !== '') {
                    $context['actor_username'] = $name;
                }
            }
            /** @var mixed $emailAttr */
            $emailAttr = $actor->getAttribute('email');
            if (is_string($emailAttr)) {
                $email = trim($emailAttr);
                if ($email !== '') {
                    $context['actor_email'] = $email;
                }
            }
        }

        $ifMatch = $request->headers->get('If-Match');
        if (! $this->etagMatches($ifMatch, $currentEtag)) {
            return response()->json([
                'ok' => false,
                'code' => 'PRECONDITION_FAILED',
                'message' => 'If-Match header required or did not match the current ETag',
                'current_etag' => $currentEtag,
            ], 409)->withHeaders($baseHeaders);
        }

        $result = $this->settings->apply(
            accepted: $accepted,
            actorId: $actorId,
            context: $context
        );

        /** @var array{core: array<string, mixed>} $effectiveAfter */
        $effectiveAfter = $this->settings->effectiveConfig();
        $newEtag = $this->settings->etagFor($effectiveAfter);

        return response()->json([
            'ok' => true,
            'applied' => true,
            'accepted' => $accepted,
            'changes' => $result['changes'],
            'config' => $effectiveAfter,
            'etag' => $newEtag,
        ], 200)->withHeaders([
            'ETag' => $newEtag,
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function etagMatches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $candidates = array_filter(array_map('trim', explode(',', $header)));

        foreach ($candidates as $candidate) {
            if ($candidate === '*') {
                return true;
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
