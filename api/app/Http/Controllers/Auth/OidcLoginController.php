<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\Auth\OidcAuthenticatorContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OidcLoginRequest;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Support\AuthTokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class OidcLoginController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly OidcAuthenticatorContract $authenticator
    ) {}

    public function login(OidcLoginRequest $request): JsonResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return response()->json([
                'ok' => false,
                'code' => 'IDP_PERSISTENCE_DISABLED',
            ], 409);
        }

        /** @var array{provider:string,code?:string,id_token?:string,redirect_uri?:string,code_verifier?:string,nonce?:string} $input */
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
}
