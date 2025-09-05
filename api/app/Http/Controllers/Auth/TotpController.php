<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TotpController extends Controller
{
    public function enroll(): JsonResponse
    {
        // placeholder payload
        return response()->json(['otpauthUri' => 'otpauth://totp/phpGRC:placeholder', 'secret' => 'PLACEHOLDER']);
    }

    public function verify(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
