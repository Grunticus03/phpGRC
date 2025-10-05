<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Requests\Setup\TotpVerifyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Verify admin TOTP code (stub). Accepts any 6-digit code "123456" in stub path.
 */
final class AdminMfaController extends Controller
{
    public function verify(TotpVerifyRequest $request): JsonResponse
    {
        /** @var array{code:string} $data */
        $data = $request->validated();
        $code = $data['code'];

        if ($code !== '123456') {
            return response()->json(['ok' => false, 'code' => 'TOTP_CODE_INVALID'], 400);
        }

        return response()->json(['ok' => true], 200);
    }
}

