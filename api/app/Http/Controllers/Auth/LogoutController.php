<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class LogoutController extends Controller
{
    /** Placeholder only. No session/token logic. */
    public function logout(): Response
    {
        // TODO: Implement in Phase 2 auth task
        return response()->noContent(); // 204
    }
}
