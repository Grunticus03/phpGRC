<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User as AppUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class MeController extends Controller
{
    public function me(): JsonResponse
    {
        /** @var mixed $auth */
        $auth = Auth::user();
        if (!$auth instanceof AppUser) {
            return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        /** @var list<string> $roles */
        $roles = $auth->roles()
            ->pluck('name')
            ->filter(static fn ($v): bool => is_string($v))
            ->values()
            ->all();

        return response()->json([
            'ok'   => true,
            'user' => ['id' => $auth->id, 'email' => $auth->email, 'roles' => $roles],
        ], 200);
    }
}
