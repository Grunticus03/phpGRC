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

        /** @var array{name:string,email:string,password:string} $data */
        $data = $request->validated();

        $user = User::query()->create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Stub enrollment output per Phase-2/4 contracts.
        $secret = strtoupper(Str::random(32)); // not real base32; stub acceptable until MFA module persists

        /** @var mixed $issuerRaw */
        $issuerRaw = config('core.auth.mfa.totp.issuer');
        $issuer = is_string($issuerRaw) && $issuerRaw !== '' ? $issuerRaw : 'phpGRC';

        $account = $user->email;

        /** @var mixed $digitsRaw */
        $digitsRaw = config('core.auth.mfa.totp.digits');
        $digits = is_int($digitsRaw) ? $digitsRaw : (is_numeric($digitsRaw) ? (int) $digitsRaw : 6);

        /** @var mixed $periodRaw */
        $periodRaw = config('core.auth.mfa.totp.period');
        $period = is_int($periodRaw) ? $periodRaw : (is_numeric($periodRaw) ? (int) $periodRaw : 30);

        /** @var mixed $algorithmRaw */
        $algorithmRaw = config('core.auth.mfa.totp.algorithm');
        $algorithm = is_string($algorithmRaw) && $algorithmRaw !== '' ? $algorithmRaw : 'SHA1';

        $otpauthUri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d&algorithm=%s',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            $digits,
            $period,
            $algorithm
        );

        return response()->json([
            'ok'   => true,
            'totp' => compact('issuer', 'account', 'secret', 'digits', 'period', 'algorithm', 'otpauthUri'),
            'user' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ], 200);
    }
}

