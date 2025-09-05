<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class LogoutController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
