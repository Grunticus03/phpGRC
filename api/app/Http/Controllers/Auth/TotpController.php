<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TotpController extends Controller
{
    /** Placeholder enroll. Returns static seed fields. */
    public function enroll(): JsonResponse
    {
        // TODO: Implement real TOTP seed generation
        return response()->json([
            'otpauthUri' => 'otpauth://totp/phpGRC:placeholder?secret=PLACEHOLDER&issuer=phpGRC&digits=6&period=30&algorithm=SHA1',
            'secret' => 'PLACEHOLDER',
        ]);
    }

    /** Placeholder verify. Always returns ok. */
    public function verify(Request $request): JsonResponse
    {
        // TODO: Validate code and bind MFA
        return response()->json(['ok' => true]);
    }
}
