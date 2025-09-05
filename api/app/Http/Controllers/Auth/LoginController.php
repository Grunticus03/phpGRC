<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class LoginController extends Controller
{
    // TODO: replace placeholder with real auth in later phase
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]); // placeholder
    }
}
