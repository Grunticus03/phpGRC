<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User as AppUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class MeController extends Controller
{
    /**
     * @SuppressWarnings("PMD.ShortMethodName")
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function me(): JsonResponse
    {
        // Ensure Sanctum PATs are used.
        Auth::shouldUse('sanctum');

        /** @var mixed $auth */
        $auth = Auth::user();
        if (! $auth instanceof AppUser) {
            logger()->info('MeController unauthenticated.', [
                'bearer_prefix' => substr((string) request()->bearerToken(), 0, 12),
                'cookies' => array_keys((array) request()->cookies?->all()),
            ]);
            return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        /** @var list<string> $roles */
        $roles = $auth->roles()
            ->pluck('name')
            ->filter(static fn ($v): bool => is_string($v))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'user' => ['id' => $auth->id, 'email' => $auth->email, 'roles' => $roles],
        ], 200);
    }
}
