<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\Auth\SamlAuthenticatorContract;
use App\Http\Controllers\Controller;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\SamlStateStore;
use App\Support\AuthTokenCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SamlAssertionConsumerController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly SamlStateStore $state,
        private readonly SamlAuthenticatorContract $authenticator
    ) {}

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function consume(Request $request): RedirectResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return $this->callbackRedirect(null, 'Identity provider persistence is unavailable.');
        }

        $relayState = $this->stringValue($request->input('RelayState', $request->input('relay_state')));
        if ($relayState === null) {
            return $this->callbackRedirect(null, 'Missing RelayState in SAML response.');
        }

        $statePayload = $this->state->consume($relayState, $request);
        if ($statePayload === null) {
            return $this->callbackRedirect(null, 'SAML sign-in session has expired. Please try again.');
        }

        $providerIdentifier = $statePayload['provider_id'] ?? $statePayload['provider_key'] ?? '';
        if ($providerIdentifier === '') {
            return $this->callbackRedirect($this->sanitizeReturnPath($statePayload['intended'] ?? null), 'SAML sign-in session is invalid.');
        }

        $provider = $this->providers->findByIdOrKey($providerIdentifier);
        if (! $provider instanceof IdpProvider) {
            return $this->callbackRedirect($this->sanitizeReturnPath($statePayload['intended'] ?? null), 'Configured SAML provider not found.');
        }

        $payload = [
            'SAMLResponse' => $request->input('SAMLResponse'),
            'request_id' => $statePayload['request_id'] ?? null,
        ];

        try {
            $user = $this->authenticator->authenticate($provider, $payload, $request);
        } catch (ValidationException $e) {
            $message = $this->firstErrorMessage($e->errors()) ?? 'SAML sign-in failed. Please try again.';

            return $this->callbackRedirect($this->sanitizeReturnPath($statePayload['intended'] ?? null), $message);
        }

        /** @psalm-suppress TooManyTemplateParams */
        /**
         * @var \Illuminate\Database\Eloquent\Relations\MorphMany<\Laravel\Sanctum\PersonalAccessToken, \App\Models\User> $tokens
         */
        $tokens = $user->tokens();
        $tokens->where('name', 'spa')->delete();

        $token = $user
            ->createToken('spa', ['*'], AuthTokenCookie::expiresAt())
            ->plainTextToken;

        $destination = $this->sanitizeReturnPath($statePayload['intended'] ?? null);

        $response = $this->callbackRedirect($destination, null);

        return $response->withCookie(AuthTokenCookie::issue($token, $request));
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
