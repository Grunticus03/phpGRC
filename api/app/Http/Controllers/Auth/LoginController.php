<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    /**
     * Token-based login for SPA.
     * Returns: { ok:true, token:string, user:{ id, email, roles:[] } }
     */
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var array{email:string,password:string} $data */
        $data = $request->validate([
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        if (!$user instanceof User) {
            $this->auditFailed($audit, $request, $data['email']);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ])->status(401);
        }

        $hashed = $user->getAuthPassword(); // string
        if (!Hash::check($data['password'], $hashed)) {
            $this->auditFailed($audit, $request, $data['email']);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ])->status(401);
        }

        // Create a Sanctum personal access token
        $token = $user->createToken('spa')->plainTextToken;

        // Audit success
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

        return response()->json([
            'ok'   => true,
            'token'=> $token,
            'user' => [
                'id'    => $user->id,
                'email' => $user->email,
                'roles' => $roleNames,
            ],
        ], 200);
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
