<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\Auth\OidcAuthenticatorContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OidcLoginRequest;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\OidcStateStore;
use App\Support\AuthTokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class OidcLoginController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly OidcAuthenticatorContract $authenticator,
        private readonly OidcStateStore $stateStore
    ) {}

    /**
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function login(OidcLoginRequest $request): JsonResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PERSISTENCE_DISABLED',
            ], 409);
        }

        /** @var array{provider:string,code?:string,id_token?:string,redirect_uri?:string,code_verifier?:string,nonce?:string,state?:string} $input */
        $input = $request->validated();
        $identifier = $input['provider'];

        $provider = $this->providers->findByIdOrKey($identifier);
        if (! $provider instanceof IdpProvider) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PROVIDER_NOT_FOUND',
                'provider' => $identifier,
            ], 404);
        }

        if (! $provider->enabled) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PROVIDER_DISABLED',
            ], 403);
        }

        $driver = strtolower($provider->driver);
        if (! in_array($driver, ['oidc', 'entra'], true)) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PROVIDER_UNSUPPORTED',
            ], 422);
        }

        $stateValue = array_key_exists('state', $input)
            ? trim($input['state'])
            : null;

        if ($stateValue !== null) {
            if ($stateValue === '') {
                $stateValue = null;
            }

            if ($stateValue !== null) {
                $statePayload = $this->stateStore->consume($stateValue, $request);
                if ($statePayload === null) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'IDP_OIDC_STATE_INVALID',
                    ], 422);
                }

                $matchesId = ($statePayload['provider_id'] ?? '') === $provider->id;
                $matchesKey = ($statePayload['provider_key'] ?? '') === $provider->key;
                if (! $matchesId && ! $matchesKey) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'IDP_OIDC_STATE_MISMATCH',
                    ], 422);
                }

                $input = $this->mergeStatePayload($input, $statePayload);
            }
        }

        try {
            $user = $this->authenticator->authenticate($provider, $input, $request);
        } catch (ValidationException $e) {
            throw $e;
        }

        /**
         * @var \Illuminate\Database\Eloquent\Relations\MorphMany<\Laravel\Sanctum\PersonalAccessToken, \App\Models\User> $tokenStore
         *
         * @psalm-suppress TooManyTemplateParams
         */
        $tokenStore = $user->tokens();
        $tokenStore->where('name', 'spa')->delete();

        $token = $user
            ->createToken('spa', ['*'], AuthTokenCookie::expiresAt())
            ->plainTextToken;

        $roleNamesRaw = $user->roles()->pluck('name')->all();
        $roles = [];
        foreach ($roleNamesRaw as $roleName) {
            if (! is_string($roleName)) {
                continue;
            }

            $normalizedRole = trim($roleName);
            if ($normalizedRole === '') {
                continue;
            }

            $roles[] = $normalizedRole;
        }

        $response = response()->json([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $roles,
            ],
        ], 200);

        return $response->withCookie(AuthTokenCookie::issue($token, $request));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,string>  $statePayload
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     *
     * @return array<string,mixed>
     */
    private function mergeStatePayload(array $input, array $statePayload): array
    {
        $redirect = $statePayload['redirect_uri'] ?? null;
        if (is_string($redirect) && $redirect !== '') {
            $redirect = trim($redirect);
            $incoming = null;
            if (array_key_exists('redirect_uri', $input) && is_string($input['redirect_uri'])) {
                $incoming = trim($input['redirect_uri']);
            }

            if ($incoming !== null && $incoming !== '') {
                if ($incoming !== $redirect) {
                    throw ValidationException::withMessages([
                        'redirect_uri' => ['Redirect URI does not match authorization request.'],
                    ])->status(422);
                }
            }

            if ($incoming === null || $incoming === '') {
                $input['redirect_uri'] = $redirect;
            }
        }

        $verifier = $statePayload['code_verifier'] ?? null;
        if (is_string($verifier) && $verifier !== '') {
            $input['code_verifier'] = $verifier;
        }

        if (! isset($input['nonce']) && is_string($statePayload['nonce'] ?? null)) {
            $input['nonce'] = $statePayload['nonce'];
        }

        return $input;
    }
}
