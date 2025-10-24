<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\Auth\SamlAuthenticatorContract;
use App\Http\Controllers\Controller;
use App\Models\IdpProvider;
use App\Models\User;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\SamlStateTokenFactory;
use App\Support\AuthTokenCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SamlAssertionConsumerController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly SamlStateTokenFactory $stateTokens,
        private readonly SamlAuthenticatorContract $authenticator
    ) {}

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function consume(Request $request): RedirectResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return $this->abortAndRedirect($request, null, 'Identity provider persistence is unavailable.', 'persistence unavailable');
        }

        $relayState = $this->stringValue($request->input('RelayState', $request->input('relay_state')));
        if ($relayState === null) {
            return $this->abortAndRedirect($request, null, 'Missing RelayState in SAML response.', 'missing relay state', [
                'inputs_present' => array_keys($request->all()),
            ]);
        }

        try {
            $stateContext = $this->resolveStateContext($relayState, $request);
        } catch (UnexpectedValueException $e) {
            return $this->abortAndRedirect($request, null, 'SAML sign-in session has expired. Please try again.', 'state validation failed', [
                'relay_state' => $relayState,
                'error' => $e->getMessage(),
            ]);
        }

        $providerIdentifier = $stateContext['provider_id'] ?? $stateContext['provider_key'] ?? '';
        if ($providerIdentifier === '') {
            return $this->abortAndRedirect(
                $request,
                $this->sanitizeReturnPath($stateContext['intended'] ?? null),
                'SAML sign-in session is invalid.',
                'provider identifier missing in state',
                [
                    'relay_state' => $relayState,
                    'state_source' => $stateContext['source'],
                ]
            );
        }

        $provider = $this->providers->findByIdOrKey($providerIdentifier);
        if (! $provider instanceof IdpProvider) {
            return $this->abortAndRedirect(
                $request,
                $this->sanitizeReturnPath($stateContext['intended'] ?? null),
                'Configured SAML provider not found.',
                'provider not found',
                [
                    'relay_state' => $relayState,
                    'provider' => $providerIdentifier,
                    'state_source' => $stateContext['source'],
                ]
            );
        }

        $payload = [
            'SAMLResponse' => $request->input('SAMLResponse'),
            'request_id' => $stateContext['request_id'] ?? null,
        ];

        try {
            $user = $this->authenticator->authenticate($provider, $payload, $request);
        } catch (ValidationException $e) {
            return $this->handleValidationFailure($relayState, $provider, $stateContext, $e);
        }

        return $this->finalizeAuthentication($request, $relayState, $provider, $stateContext, $user);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function abortAndRedirect(Request $request, ?string $destination, string $publicMessage, string $reason, array $context = []): RedirectResponse
    {
        $payload = array_merge([
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $context);

        logger()->warning('SAML ACS aborted: '.$reason.'.', $payload);

        return $this->callbackRedirect($destination, $publicMessage);
    }

    /**
     * @param  array{provider_id:?string,provider_key:?string,request_id:?string,intended:?string,source:string}  $stateContext
     */
    private function handleValidationFailure(string $relayState, IdpProvider $provider, array $stateContext, ValidationException $e): RedirectResponse
    {
        $message = $this->firstErrorMessage($e->errors()) ?? 'SAML sign-in failed. Please try again.';

        logger()->warning('SAML ACS validation failed.', [
            'relay_state' => $relayState,
            'provider' => $provider->key,
            'errors' => $e->errors(),
            'state_source' => $stateContext['source'],
        ]);

        return $this->callbackRedirect($this->sanitizeReturnPath($stateContext['intended'] ?? null), $message);
    }

    /**
     * @param  array{provider_id:?string,provider_key:?string,request_id:?string,intended:?string,source:string}  $stateContext
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function finalizeAuthentication(Request $request, string $relayState, IdpProvider $provider, array $stateContext, User $user): RedirectResponse
    {
        /** @psalm-suppress TooManyTemplateParams */
        /** @var \Illuminate\Database\Eloquent\Relations\MorphMany<\Laravel\Sanctum\PersonalAccessToken, \App\Models\User> $tokens */
        $tokens = $user->tokens();
        $tokens->where('name', 'spa')->delete();

        $token = $user->createToken('spa', ['*'], AuthTokenCookie::expiresAt())->plainTextToken;
        $destination = $this->sanitizeReturnPath($stateContext['intended'] ?? null);
        $response = $this->callbackRedirect($destination, null);

        logger()->info('SAML ACS succeeded.', [
            'relay_state' => $relayState,
            'provider' => $provider->key,
            'user_id' => $user->id,
            'intended' => $destination,
            'state_source' => $stateContext['source'],
        ]);

        return $response->withCookie(AuthTokenCookie::issue($token, $request));
    }

    /**
     * @return array{provider_id:?string,provider_key:?string,request_id:?string,intended:?string,source:string}
     */
    private function resolveStateContext(string $relayState, Request $request): array
    {
        $descriptor = $this->stateTokens->validate($relayState, $request);

        return [
            'provider_id' => $descriptor->providerId,
            'provider_key' => $descriptor->providerKey,
            'request_id' => $descriptor->requestId,
            'intended' => $descriptor->intendedPath,
            'source' => 'token',
        ];
    }

    private function callbackRedirect(?string $destination, ?string $error): RedirectResponse
    {
        $params = ['saml' => '1'];
        if ($destination !== null) {
            $params['dest'] = $destination;
        }
        if ($error !== null) {
            $params['error'] = $error;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return redirect()->to('/auth/callback'.($query !== '' ? '?'.$query : ''));
    }

    /**
     * @param  array<array-key, mixed>  $errors
     */
    private function firstErrorMessage(array $errors): ?string
    {
        /** @var mixed $messages */
        foreach ($errors as $messages) {
            if (is_array($messages)) {
                /** @var mixed $message */
                foreach ($messages as $message) {
                    if (! is_string($message)) {
                        continue;
                    }

                    $trimmed = trim($message);
                    if ($trimmed !== '') {
                        return $trimmed;
                    }
                }

                continue;
            }

            if (is_string($messages)) {
                $trimmed = trim($messages);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sanitizeReturnPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 512) {
            return null;
        }

        if (! str_starts_with($trimmed, '/')) {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return null;
        }

        return $trimmed;
    }
}
