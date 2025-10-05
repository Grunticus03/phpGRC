<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\AuthTokenCookie;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use function assert;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    /**
     * Token login for SPA.
     * Accepts JSON or form bodies. Missing creds => 422. Bad creds => 401.
     */
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        [$email, $pass] = $this->extractCreds($request);

        if ($email === '' || $pass === '') {
            throw ValidationException::withMessages([
                'email'    => ['The email field is required.'],
                'password' => ['The password field is required.'],
            ])->status(422);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (!$user instanceof User || !Hash::check($pass, $user->getAuthPassword())) {
            $this->auditFailed($audit, $request, $email);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ])->status(401);
        }

        /**\n         * @var MorphMany<PersonalAccessToken> \n         * @psalm-var MorphMany<PersonalAccessToken> \n         * @phpstan-var MorphMany<PersonalAccessToken, User> \n         */
        $tokens = $user->tokens();
        \assert($tokens instanceof MorphMany);
        $tokens->where('name', 'spa')->delete();

        $token = $user
            ->createToken('spa', ['*'], AuthTokenCookie::expiresAt())
            ->plainTextToken;

        $audit->log([
            'actor_id'    => $user->id,
            'action'      => 'auth.login',
            'category'    => 'AUTH',
            'entity_type' => 'core.auth',
            'entity_id'   => 'login',
            'ip'          => $request->ip(),
            'ua'          => $request->userAgent(),
            'meta'        => ['token_type' => 'sanctum_pat'],
        ]);

        /** @var array<int,string> $roleNames */
        $roleNames = $user->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        $response = response()->json([
            'ok'   => true,
            'token'=> $token,
            'user' => [
                'id'    => $user->id,
                'email' => $user->email,
                'roles' => $roleNames,
            ],
        ], 200);

        return $response->withCookie(AuthTokenCookie::issue($token, $request));
    }

    /**
     * Extract credentials from JSON or form. Handles environments where
     * the JSON parser is not invoked.
     *
     * @return array{0:string,1:string}
     */
    private function extractCreds(Request $request): array
    {
        /** @var mixed $emailRaw */
        $emailRaw = $request->input('email');
        /** @var mixed $passRaw */
        $passRaw  = $request->input('password');

        $e = is_string($emailRaw) ? trim($emailRaw) : '';
        $p = is_string($passRaw)  ? $passRaw        : '';

        if ($e !== '' && $p !== '') {
            return [$e, $p];
        }

        // Try JSON bag explicitly
        try {
            /** @var mixed $je */
            $je = $request->json('email');
            /** @var mixed $jp */
            $jp = $request->json('password');
            $e2 = is_string($je) ? trim($je) : '';
            $p2 = is_string($jp) ? $jp       : '';
            if ($e2 !== '' && $p2 !== '') {
                return [$e2, $p2];
            }
        } catch (\Throwable) {
            // ignore
        }

        // Parse raw body as JSON fallback
        /** @var mixed $rawContent */
        $rawContent = $request->getContent();
        $raw = is_string($rawContent) ? $rawContent : '';

        if ($raw !== '') {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $e3 = (isset($decoded['email']) && is_string($decoded['email'])) ? trim($decoded['email']) : '';
                    $p3 = (isset($decoded['password']) && is_string($decoded['password'])) ? $decoded['password'] : '';
                    if ($e3 !== '' && $p3 !== '') {
                        return [$e3, $p3];
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return ['', ''];
    }

    private function auditFailed(AuditLogger $audit, Request $request, string $email): void
    {
        $audit->log([
            'actor_id'    => null,
            'action'      => 'auth.login.failed',
            'category'    => 'AUTH',
            'entity_type' => 'core.auth',
            'entity_id'   => 'login',
            'ip'          => $request->ip(),
            'ua'          => $request->userAgent(),
            'meta'        => ['email' => $email],
        ]);
    }
}




