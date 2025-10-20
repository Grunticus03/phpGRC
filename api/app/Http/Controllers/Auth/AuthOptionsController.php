<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use Illuminate\Http\JsonResponse;

final class AuthOptionsController extends Controller
{
    public function __construct(private readonly IdpProviderService $providers) {}

    public function show(): JsonResponse
    {
        $localEnabled = $this->normalizeBoolean(config('core.auth.local.enabled', true));
        $requireAdminMfa = $this->normalizeBoolean(config('core.auth.mfa.totp.required_for_admin', true));

        $providers = [];
        if ($this->providers->persistenceAvailable()) {
            $enabledProviders = IdpProvider::query()
                ->where('enabled', '=', true)
                ->orderBy('evaluation_order')
                ->orderBy('name')
                ->get();

            foreach ($enabledProviders as $provider) {
                $providers[] = [
                    'id' => $provider->id,
                    'key' => $provider->key,
                    'name' => $provider->name,
                    'driver' => strtolower($provider->driver),
                    'links' => [
                        'authorize' => $this->authorizeLink($provider),
                    ],
                ];
            }
        }

        $mode = 'local_only';
        if (! $localEnabled && $providers === []) {
            $mode = 'none';
        } elseif (! $localEnabled) {
            $mode = 'idp_only';
        } elseif ($providers !== []) {
            $mode = 'mixed';
        }

        $autoRedirect = null;
        if (! $localEnabled && count($providers) === 1) {
            /** @var array{id:string,key:string,name:string,driver:lowercase-string,links:array{authorize:?string}} $only */
            $only = $providers[0];
            $authorize = $only['links']['authorize'] ?? null;
            if ($authorize !== null && $authorize !== '') {
                $autoRedirect = [
                    'provider' => $only['id'],
                    'key' => $only['key'],
                    'driver' => $only['driver'],
                    'authorize' => $authorize,
                ];
            }
        }

        return response()->json([
            'ok' => true,
            'mode' => $mode,
            'local' => [
                'enabled' => $localEnabled,
                'mfa' => [
                    'totp' => [
                        'required_for_admin' => $requireAdminMfa,
                    ],
                ],
            ],
            'idp' => [
                'providers' => $providers,
            ],
            'auto_redirect' => $autoRedirect,
        ]);
    }

    private function authorizeLink(IdpProvider $provider): ?string
    {
        $driver = strtolower($provider->driver);

        $identifier = $provider->id !== '' ? $provider->id : $provider->key;
        if ($identifier === '') {
            return null;
        }

        $encoded = rawurlencode($identifier);

        return match ($driver) {
            'oidc', 'entra' => "/api/auth/oidc/authorize?provider={$encoded}",
            'saml' => "/api/auth/saml/redirect?provider={$encoded}",
            default => null,
        };
    }

    private function normalizeBoolean(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw !== 0;
        }

        if (is_float($raw)) {
            return (int) $raw !== 0;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if ($normalized === '') {
                return false;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $raw;
    }
}
