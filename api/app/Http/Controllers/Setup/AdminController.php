<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Requests\Setup\AdminCreateRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create first admin user. Returns TOTP enrollment payload (stub-compatible).
 */
final class AdminController extends Controller
{
    public function create(AdminCreateRequest $request): JsonResponse
    {
        if (User::query()->exists()) {
            return response()->json(['ok' => false, 'code' => 'ADMIN_EXISTS'], 409);
        }

        $data = $request->validated();

        $user = User::query()->create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Stub enrollment output per Phase-2/4 contracts. :contentReference[oaicite:4]{index=4}
        $secret    = strtoupper(Str::random(32)); // not real base32; stub acceptable until MFA module persists
        $issuer    = config('core.auth.mfa.totp.issuer', 'phpGRC');
        $account   = $user->email;
        $digits    = (int) config('core.auth.mfa.totp.digits', 6);
        $period    = (int) config('core.auth.mfa.totp.period', 30);
        $algorithm = (string) config('core.auth.mfa.totp.algorithm', 'SHA1');

        $otpauthUri = sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d&algorithm=%s',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            $digits,
            $period,
            $algorithm
        );

        return response()->json([
            'ok'    => true,
            'totp'  => compact('issuer', 'account', 'secret', 'digits', 'period', 'algorithm', 'otpauthUri'),
            'user'  => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ], 200);
    }
}

