<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class LoginController extends Controller
{
    /** Placeholder only. No auth logic. */
    public function login(Request $request): JsonResponse
    {
        // TODO: Implement in Phase 2 auth task
        return response()->json(['ok' => true, 'note' => 'placeholder'], 200);
    }
}
