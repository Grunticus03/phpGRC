<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class MeController extends Controller
{
    /** Returns 200 with user when authenticated via Sanctum token. Else 401. */
    public function me(): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();
        if ($user === null) {
            return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        /** @var array<int,string> $roleNames */
        $roleNames = $user->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        return response()->json([
            'ok'   => true,
            'user' => [
                'id'    => $user->id,
                'email' => $user->email,
                'roles' => $roleNames,
            ],
        ], 200);
    }
}
