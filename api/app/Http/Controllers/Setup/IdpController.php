<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Services\Auth\IdpProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Setup hook for provisioning the first Identity Provider entry.
 */
final class IdpController extends Controller
{
    public function __construct(private readonly IdpProviderService $service = new IdpProviderService) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->service->persistenceAvailable()) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PERSISTENCE_DISABLED',
            ], 409);
        }

        $defaults = [
            'key' => 'primary',
            'name' => 'Primary IdP',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [],
        ];

        /** @var array<string, mixed> $payload */
        $payload = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'driver' => ['sometimes', 'required', 'string', 'in:oidc,saml,ldap,entra'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['required', 'array'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        $data = array_replace($defaults, $payload);
        /** @var array<string,mixed> $configPayload */
        $configPayload = is_array($data['config'] ?? null) ? $data['config'] : [];

        if (($data['driver'] ?? '') === 'oidc') {
            foreach (['issuer', 'client_id', 'client_secret'] as $required) {
                if (
                    ! array_key_exists($required, $configPayload)
                    || ! is_string($configPayload[$required])
                    || trim($configPayload[$required]) === ''
                ) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'IDP_CONFIG_INVALID',
                        'errors' => ["config.$required" => ['This field is required for OIDC providers.']],
                    ], 422);
                }
            }
        }

        /** @var string $key */
        $key = $data['key'];
        $existing = $this->service->findByIdOrKey($key);

        if ($existing === null) {
            $provider = $this->service->create([
                'key' => $data['key'],
                'name' => $data['name'],
                'driver' => $data['driver'],
                'enabled' => (bool) ($data['enabled'] ?? true),
                'evaluation_order' => 1,
                'config' => $configPayload,
                'meta' => $data['meta'] ?? null,
            ]);
        } else {
            $provider = $this->service->update($existing, [
                'name' => $data['name'],
                'driver' => $data['driver'],
                'enabled' => (bool) ($data['enabled'] ?? true),
                'evaluation_order' => 1,
                'config' => $configPayload,
                'meta' => $data['meta'] ?? null,
            ]);
        }

        return response()->json([
            'ok' => true,
            'provider' => [
                'id' => $provider->id,
                'key' => $provider->key,
            ],
        ], 200);
    }
}
