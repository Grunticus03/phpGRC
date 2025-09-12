<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Requests\Setup\SmtpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Store SMTP settings (stub path returns 200 after validation).
 */
final class SmtpController extends Controller
{
    public function store(SmtpRequest $request): JsonResponse
    {
        // Phase-4 scopes persistence to DB-backed settings; for now just validate and echo. :contentReference[oaicite:5]{index=5}
        $data = $request->validated();

        return response()->json([
            'ok'     => true,
            'stored' => ['host' => $data['host'], 'port' => $data['port'], 'secure' => $data['secure'], 'fromEmail' => $data['fromEmail']],
        ], 200);
    }
}

